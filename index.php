<?php
require 'vendor/autoload.php';

use Dotenv\Dotenv;

session_start();

// 1. LOAD CONFIG
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// 1A. DATABASE CONNECTION (SQLite3)
function getDB() {
    $dbPath = $_ENV['DB_PATH'] ?? __DIR__ . '/database.sqlite';
    
    try {
        $db = new SQLite3($dbPath);
        $db->busyTimeout(5000);
        return $db;
    } catch (Exception $e) {
        error_log('Database connection error: ' . $e->getMessage());
        return null;
    }
}

// 1B. INITIALIZE DATABASE TABLES (SQLite Syntax)
function initDatabase() {
    $db = getDB();
    if (!$db) return false;
    
    // Create users table
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL,
        subscription_status TEXT DEFAULT 'free',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Create saved_items table
    $db->exec("CREATE TABLE IF NOT EXISTS saved_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        type TEXT NOT NULL,
        amount TEXT,
        deadline TEXT,
        link TEXT,
        match_reason TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    
    // Create usage_logs table for rate limiting
    $db->exec("CREATE TABLE IF NOT EXISTS usage_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        search_date TEXT NOT NULL,
        count INTEGER DEFAULT 0,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE(user_id, search_date)
    )");
    
    $db->close();
    return true;
}

// Initialize database on load
initDatabase();

// 1C. STRIPE INITIALIZATION
\Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY'] ?? '');

// 2. HELPER: Clean AI Output
function cleanAIJson($text) {
    $text = preg_replace('/^`{3}(json)?/m', '', $text);
    $text = preg_replace('/`{3}$/m', '', $text);
    return trim($text);
}

// 2A. HELPER: Check Authentication
function isAuthenticated() {
    return isset($_SESSION['user_id']);
}

// 2B. HELPER: Require Authentication
function requireAuth() {
    if (!isAuthenticated()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized. Please log in.']);
        exit;
    }
}

// 2C. HELPER: Get User's Daily Search Count
function getDailySearchCount($userId) {
    $db = getDB();
    if (!$db) return 0;
    
    $today = date('Y-m-d');
    $stmt = $db->prepare("SELECT count FROM usage_logs WHERE user_id = :user_id AND search_date = :search_date");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':search_date', $today, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    $db->close();
    return $row ? (int)$row['count'] : 0;
}

// 2D. HELPER: Increment User's Daily Search Count
function incrementSearchCount($userId) {
    $db = getDB();
    if (!$db) return false;
    
    $today = date('Y-m-d');
    
    // Check if record exists
    $stmt = $db->prepare("SELECT id, count FROM usage_logs WHERE user_id = :user_id AND search_date = :search_date");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':search_date', $today, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($row) {
        // Update existing record
        $stmt = $db->prepare("UPDATE usage_logs SET count = count + 1 WHERE user_id = :user_id AND search_date = :search_date");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':search_date', $today, SQLITE3_TEXT);
        $success = $stmt->execute();
    } else {
        // Insert new record
        $stmt = $db->prepare("INSERT INTO usage_logs (user_id, search_date, count) VALUES (:user_id, :search_date, 1)");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':search_date', $today, SQLITE3_TEXT);
        $success = $stmt->execute();
    }
    
    $db->close();
    return $success !== false;
}

// 2E. HELPER: Check Rate Limit
function checkRateLimit($userId) {
    $db = getDB();
    if (!$db) return ['allowed' => false, 'error' => 'Database connection failed'];
    
    // Get user's subscription status
    $stmt = $db->prepare("SELECT subscription_status FROM users WHERE id = :user_id");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$user) {
        $db->close();
        return ['allowed' => false, 'error' => 'User not found'];
    }
    
    // Pro users have unlimited searches
    if ($user['subscription_status'] === 'active') {
        $db->close();
        return ['allowed' => true, 'subscription' => 'active'];
    }
    
    // Free users: check daily limit (3 searches)
    $db->close();
    $dailyCount = getDailySearchCount($userId);
    $freeLimit = 3;
    
    if ($dailyCount >= $freeLimit) {
        return [
            'allowed' => false, 
            'error' => 'Free limit reached. Upgrade to Pro for unlimited searches.',
            'daily_count' => $dailyCount,
            'limit' => $freeLimit
        ];
    }
    
    return ['allowed' => true, 'subscription' => 'free', 'daily_count' => $dailyCount];
}

// 3. CORE LOGIC: AI Search (UPDATED: GEO-ENFORCER)
function searchFunding($type, $location, $purpose) {
    // FIX 1: The "Broom" - Trim hidden spaces/newlines from the key
    $apiKey = trim($_ENV['GEMINI_API_KEY'] ?? '');
    
    if (empty($apiKey)) {
        return ['error' => 'Server Config Error: API Key missing.'];
    }

    // NEW: Strict Geographic & Readiness Instructions
    $systemInstruction = "You are a senior funding consultant. Your specialized skill is 'Geographic Precision'.";
    
    $userPrompt = <<<EOT
    CONTEXT:
    User Business: $type
    Location: $location
    Need: $purpose

    CRITICAL RULES FOR SELECTION:
    1. GEOGRAPHIC PRIORITY: You must prioritize funding sources specifically for $location. 
       - If $location is a city, look for City-level grants first, then County, then State.
       - Do NOT recommend generic national loans (like generic SBA loans) unless they have a specific initiative for this industry.
    
    2. REALITY CHECK: 
       - If the business sounds like a small local business (restaurant, retail, service), DO NOT recommend "Venture Capital" or "Angel Investors" unless they are explicitly local community investors. Focus on Grants, CDFIs, and Microloans.
       - If it is a Tech Startup, prioritize Accelerators and Pre-seed funds that accept founders from $location.

    3. ADMIN & MATCH REASONING:
       - For 'match_reason', explicitly state WHY the location matches (e.g., "Available specifically for businesses in King County").

    TASK:
    Identify 3 highly relevant funding sources.

    OUTPUT FORMAT:
    Return ONLY a valid JSON Array. Schema:
    [
        {
            "name": "Name of Funding",
            "type": "Grant/Loan/Investor",
            "amount": "$ Amount",
            "deadline": "Date or 'Rolling'",
            "link": "Application URL",
            "match_reason": "Specific reason this fits the location and type."
        }
    ]
    EOT;

    $data = [
        "contents" => [
            [
                "parts" => [
                    ["text" => $systemInstruction . "\n\n" . $userPrompt]
                ]
            ]
        ],
        "generationConfig" => [
            "temperature" => 0.2, // Low temperature for strict adherence
            "maxOutputTokens" => 1000,
            "responseMimeType" => "application/json" 
        ]
    ];

    // FIX 2: Updated Model URL to Flash Lite Latest
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-lite-preview-02-05:generateContent?key=" . urlencode($apiKey);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    // FIX 3: Force IPv4
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        return ['error' => 'Curl error: ' . curl_error($ch)];
    }
    curl_close($ch);
    
    $jsonResponse = json_decode($response, true);

    if (isset($jsonResponse['candidates'][0]['content']['parts'][0]['text'])) {
        $rawText = $jsonResponse['candidates'][0]['content']['parts'][0]['text'];
        $cleanText = cleanAIJson($rawText);
        $parsedData = json_decode($cleanText, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $parsedData;
        } else {
            return ['error' => 'AI output was not valid JSON', 'raw' => $rawText];
        }
    }

    return ['error' => 'Invalid API Response structure', 'debug' => $jsonResponse];
}

// 4. ROUTING
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// 4A. AUTHENTICATION ROUTES

// Register new user
if ($uri === '/api/register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['name']) || !isset($input['email']) || !isset($input['password'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields: name, email, password']);
        exit;
    }
    
    $db = getDB();
    if (!$db) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed']);
        exit;
    }
    
    // Check if email already exists
    $stmt = $db->prepare("SELECT id FROM users WHERE email = :email");
    $stmt->bindValue(':email', $input['email'], SQLITE3_TEXT);
    $result = $stmt->execute();
    if ($result->fetchArray(SQLITE3_ASSOC)) {
        $db->close();
        http_response_code(409);
        echo json_encode(['error' => 'Email already registered']);
        exit;
    }
    
    // Hash password and create user
    $hashedPassword = password_hash($input['password'], PASSWORD_BCRYPT);
    $stmt = $db->prepare("INSERT INTO users (name, email, password) VALUES (:name, :email, :password)");
    $stmt->bindValue(':name', $input['name'], SQLITE3_TEXT);
    $stmt->bindValue(':email', $input['email'], SQLITE3_TEXT);
    $stmt->bindValue(':password', $hashedPassword, SQLITE3_TEXT);
    
    if ($stmt->execute()) {
        $userId = $db->lastInsertRowID();
        
        // Start session
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_name'] = $input['name'];
        $_SESSION['user_email'] = $input['email'];
        
        $db->close();
        echo json_encode([
            'success' => true,
            'user' => [
                'id' => $userId,
                'name' => $input['name'],
                'email' => $input['email']
            ]
        ]);
    } else {
        $db->close();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create user']);
    }
    exit;
}

// Login
if ($uri === '/api/login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['email']) || !isset($input['password'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields: email, password']);
        exit;
    }
    
    $db = getDB();
    if (!$db) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed']);
        exit;
    }
    
    $stmt = $db->prepare("SELECT id, name, email, password FROM users WHERE email = :email");
    $stmt->bindValue(':email', $input['email'], SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$user || !password_verify($input['password'], $user['password'])) {
        $db->close();
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or password']);
        exit;
    }
    
    // Start session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    
    $db->close();
    echo json_encode([
        'success' => true,
        'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email']
        ]
    ]);
    exit;
}

// Logout
if ($uri === '/api/logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    session_destroy();
    echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
    exit;
}

// Check authentication status
if ($uri === '/api/me' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    
    if (isAuthenticated()) {
        // Fetch subscription status from database
        $db = getDB();
        $subscriptionStatus = 'free';
        $dailySearchesLeft = 0;
        
        if ($db) {
            $stmt = $db->prepare("SELECT subscription_status FROM users WHERE id = :user_id");
            $stmt->bindValue(':user_id', $_SESSION['user_id'], SQLITE3_INTEGER);
            $result = $stmt->execute();
            $user = $result->fetchArray(SQLITE3_ASSOC);
            if ($user) {
                $subscriptionStatus = $user['subscription_status'];
            }
            
            // Calculate daily searches left
            if ($subscriptionStatus === 'active') {
                $dailySearchesLeft = 'unlimited';
            } else {
                $dailyCount = getDailySearchCount($_SESSION['user_id']);
                $dailySearchesLeft = max(0, 3 - $dailyCount);
            }
            
            $db->close();
        }
        
        echo json_encode([
            'authenticated' => true,
            'user' => [
                'id' => $_SESSION['user_id'],
                'name' => $_SESSION['user_name'],
                'email' => $_SESSION['user_email'],
                'subscription_status' => $subscriptionStatus,
                'daily_searches_left' => $dailySearchesLeft
            ]
        ]);
    } else {
        echo json_encode(['authenticated' => false]);
    }
    exit;
}

// 4A-2. STRIPE PAYMENT ROUTES

// Create Stripe Checkout Session
if ($uri === '/api/checkout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    requireAuth(); // User must be logged in
    
    $stripeSecretKey = $_ENV['STRIPE_SECRET_KEY'] ?? '';
    if (empty($stripeSecretKey)) {
        http_response_code(500);
        echo json_encode(['error' => 'Stripe not configured']);
        exit;
    }
    
    try {
        // Create Stripe Checkout Session
        $session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => 'FundFinder Pro',
                        'description' => 'Unlimited funding searches and saved items',
                    ],
                    'unit_amount' => 4900, // $49.00
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => ($_ENV['APP_URL'] ?? 'http://localhost:8000') . '/payment-success.html?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => ($_ENV['APP_URL'] ?? 'http://localhost:8000') . '/pricing.html',
            'client_reference_id' => (string)$_SESSION['user_id'], // Link payment to user
        ]);
        
        echo json_encode(['sessionId' => $session->id]);
    } catch (\Stripe\Exception\ApiErrorException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create checkout session: ' . $e->getMessage()]);
    }
    exit;
}

// Verify payment and upgrade user to Pro
if ($uri === '/api/payment-success' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    requireAuth(); // User must be logged in
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['session_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing session_id']);
        exit;
    }
    
    $stripeSecretKey = $_ENV['STRIPE_SECRET_KEY'] ?? '';
    if (empty($stripeSecretKey)) {
        http_response_code(500);
        echo json_encode(['error' => 'Stripe not configured']);
        exit;
    }
    
    try {
        // Retrieve the session from Stripe
        $session = \Stripe\Checkout\Session::retrieve($input['session_id']);
        
        // Verify payment was successful
        if ($session->payment_status !== 'paid') {
            http_response_code(400);
            echo json_encode(['error' => 'Payment not completed']);
            exit;
        }
        
        // Get the user ID from client_reference_id
        $userId = $session->client_reference_id;
        
        if (empty($userId)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid session data']);
            exit;
        }
        
        // Update user's subscription status in database
        $db = getDB();
        if (!$db) {
            http_response_code(500);
            echo json_encode(['error' => 'Database connection failed']);
            exit;
        }
        
        $stmt = $db->prepare("UPDATE users SET subscription_status = 'active' WHERE id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->execute();
        
        $db->close();
        echo json_encode([
            'success' => true,
            'message' => 'Subscription activated successfully!',
            'subscription_status' => 'active'
        ]);
    } catch (\Stripe\Exception\ApiErrorException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to verify payment: ' . $e->getMessage()]);
    }
    exit;
}

// 4B. PROTECTED ROUTES - Save funding item
if ($uri === '/api/save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    requireAuth(); // Protect this route
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['name']) || !isset($input['type'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        exit;
    }
    
    $db = getDB();
    if (!$db) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed']);
        exit;
    }
    
    $stmt = $db->prepare("INSERT INTO saved_items (user_id, name, type, amount, deadline, link, match_reason) 
                          VALUES (:user_id, :name, :type, :amount, :deadline, :link, :match_reason)");
    $stmt->bindValue(':user_id', $_SESSION['user_id'], SQLITE3_INTEGER);
    $stmt->bindValue(':name', $input['name'], SQLITE3_TEXT);
    $stmt->bindValue(':type', $input['type'], SQLITE3_TEXT);
    $stmt->bindValue(':amount', $input['amount'] ?? null, SQLITE3_TEXT);
    $stmt->bindValue(':deadline', $input['deadline'] ?? null, SQLITE3_TEXT);
    $stmt->bindValue(':link', $input['link'] ?? null, SQLITE3_TEXT);
    $stmt->bindValue(':match_reason', $input['match_reason'] ?? null, SQLITE3_TEXT);
    
    if ($stmt->execute()) {
        $db->close();
        echo json_encode(['success' => true, 'message' => 'Item saved successfully']);
    } else {
        $db->close();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save item']);
    }
    exit;
}

// Get saved items
if ($uri === '/api/saved' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    requireAuth(); // Protect this route
    
    $db = getDB();
    if (!$db) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed']);
        exit;
    }
    
    $stmt = $db->prepare("SELECT id, name, type, amount, deadline, link, match_reason, created_at 
                          FROM saved_items WHERE user_id = :user_id ORDER BY created_at DESC");
    $stmt->bindValue(':user_id', $_SESSION['user_id'], SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $items = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $items[] = $row;
    }
    
    $db->close();
    echo json_encode(['items' => $items]);
    exit;
}

// 4C. PROTECTED ROUTES - AI Search (Rate Limited)
// API Endpoint called by your frontend
if ($uri === '/api/search' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // REQUIRE AUTHENTICATION
    if (!isAuthenticated()) {
        http_response_code(401);
        echo json_encode(['error' => 'Please login to search']);
        exit;
    }
    
    // CHECK RATE LIMIT
    $rateLimitCheck = checkRateLimit($_SESSION['user_id']);
    if (!$rateLimitCheck['allowed']) {
        http_response_code(429);
        echo json_encode([
            'error' => $rateLimitCheck['error'],
            'daily_count' => $rateLimitCheck['daily_count'] ?? null,
            'limit' => $rateLimitCheck['limit'] ?? null
        ]);
        exit;
    }
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Basic validation
    if (!$input || !isset($input['type']) || !isset($input['location'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        exit;
    }

    // Execute AI Search
    $results = searchFunding($input['type'], $input['location'], $input['purpose'] ?? 'General funding');
    
    // INCREMENT SEARCH COUNT (only if search was successful)
    if (!isset($results['error'])) {
        incrementSearchCount($_SESSION['user_id']);
    }
    
    echo json_encode($results);
    exit;
}

// Serve the Frontend for the root URL
if ($uri === '/' || $uri === '/index.html') {
    readfile('index.html'); 
    exit;
}

// 404 for everything else
http_response_code(404);
echo json_encode(['error' => 'Not Found']);
// Display basic information
echo "Fund Finder v3";

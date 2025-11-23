<?php
require 'vendor/autoload.php';

use Dotenv\Dotenv;

session_start();

// 1. LOAD CONFIG
// Ensure you have a .env file with GEMINI_API_KEY=...
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// 1A. DATABASE CONNECTION
function getDB() {
    $host = $_ENV['DB_HOST'] ?? 'localhost';
    $dbname = $_ENV['DB_NAME'] ?? 'fundfinder';
    $user = $_ENV['DB_USER'] ?? 'root';
    $pass = $_ENV['DB_PASS'] ?? '';
    
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        return null;
    }
}

// 1B. INITIALIZE DATABASE TABLES
function initDatabase() {
    $db = getDB();
    if (!$db) return false;
    
    // Create users table with subscription_status
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        subscription_status TEXT DEFAULT 'free',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Create saved_items table with user_id
    $db->exec("CREATE TABLE IF NOT EXISTS saved_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        type VARCHAR(50) NOT NULL,
        amount VARCHAR(100),
        deadline VARCHAR(100),
        link TEXT,
        match_reason TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    
    return true;
}

// Initialize database on load
initDatabase();

// 1C. STRIPE INITIALIZATION
\Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY'] ?? '');

// 2. HELPER: Clean AI Output
// LLMs often wrap JSON in markdown like ```json ... ```. This strips that out.
function cleanAIJson($text) {
    // Remove markdown code blocks
    $text = preg_replace('/^`{3}(json)?/m', '', $text);
    $text = preg_replace('/`{3}$/m', '', $text);
    // Remove any leading/trailing whitespace
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
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$input['email']]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Email already registered']);
        exit;
    }
    
    // Hash password and create user
    $hashedPassword = password_hash($input['password'], PASSWORD_BCRYPT);
    $stmt = $db->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
    
    try {
        $stmt->execute([$input['name'], $input['email'], $hashedPassword]);
        $userId = $db->lastInsertId();
        
        // Start session
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_name'] = $input['name'];
        $_SESSION['user_email'] = $input['email'];
        
        echo json_encode([
            'success' => true,
            'user' => [
                'id' => $userId,
                'name' => $input['name'],
                'email' => $input['email']
            ]
        ]);
    } catch (PDOException $e) {
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
    
    $stmt = $db->prepare("SELECT id, name, email, password FROM users WHERE email = ?");
    $stmt->execute([$input['email']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || !password_verify($input['password'], $user['password'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or password']);
        exit;
    }
    
    // Start session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    
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
        
        if ($db) {
            $stmt = $db->prepare("SELECT subscription_status FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $subscriptionStatus = $user['subscription_status'];
            }
        }
        
        echo json_encode([
            'authenticated' => true,
            'user' => [
                'id' => $_SESSION['user_id'],
                'name' => $_SESSION['user_name'],
                'email' => $_SESSION['user_email'],
                'subscription_status' => $subscriptionStatus
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
                    'unit_amount' => 2900, // $29.00
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
        
        $stmt = $db->prepare("UPDATE users SET subscription_status = 'active' WHERE id = ?");
        $stmt->execute([$userId]);
        
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
    
    $stmt = $db->prepare("INSERT INTO saved_items (user_id, name, type, amount, deadline, link, match_reason) VALUES (?, ?, ?, ?, ?, ?, ?)");
    
    try {
        $stmt->execute([
            $_SESSION['user_id'],
            $input['name'],
            $input['type'],
            $input['amount'] ?? null,
            $input['deadline'] ?? null,
            $input['link'] ?? null,
            $input['match_reason'] ?? null
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Item saved successfully']);
    } catch (PDOException $e) {
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
    
    $stmt = $db->prepare("SELECT id, name, type, amount, deadline, link, match_reason, created_at FROM saved_items WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$_SESSION['user_id']]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['items' => $items]);
    exit;
}

// 4C. PUBLIC ROUTES - AI Search
// API Endpoint called by your frontend
if ($uri === '/api/search' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
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

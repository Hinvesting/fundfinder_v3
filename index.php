<?php
require 'vendor/autoload.php';

use Dotenv\Dotenv;

session_start();

// 1. LOAD CONFIG
// Ensure you have a .env file with GEMINI_API_KEY=...
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// 2. HELPER: Clean AI Output
// LLMs often wrap JSON in markdown like ```json ... ```. This strips that out.
function cleanAIJson($text) {
    // Remove markdown code blocks
    $text = preg_replace('/^`{3}(json)?/m', '', $text);
    $text = preg_replace('/`{3}$/m', '', $text);
    // Remove any leading/trailing whitespace
    return trim($text);
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

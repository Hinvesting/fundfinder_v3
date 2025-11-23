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

// 3. CORE LOGIC: AI Search
function searchFunding($type, $location, $purpose) {
    $apiKey = $_ENV['GEMINI_API_KEY'] ?? '';
    
    if (empty($apiKey)) {
        return ['error' => 'Server Config Error: API Key missing'];
    }

    // The Prompt Engineering
    // We use a "System" persona to enforce strict JSON compliance.
    $systemInstruction = "You are a strict backend API for a funding database. You never speak. You only output raw JSON arrays.";
    
    $userPrompt = <<<EOT
    CONTEXT:
    User Business: $type
    Location: $location
    Need: $purpose

    TASK:
    Identify 3 real or highly realistic funding sources (Grants, Loans, or Angel Networks) that match this specific user.

    OUTPUT FORMAT:
    Return ONLY a valid JSON Array. No markdown. No conversational filler.
    Follow this exact schema:
    [
        {
            "name": "Name of Grant/Loan",
            "type": "Grant" or "Loan" or "Investor",
            "amount": "e.g. $5,000 - $20,000",
            "deadline": "e.g. Dec 31, 2024 or Rolling",
            "link": "[https://example.com](https://example.com)",
            "match_reason": "1 short sentence on why this fits."
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
            "temperature" => 0.2, // Low temp = more deterministic/strict
            "maxOutputTokens" => 1000,
            "responseMimeType" => "application/json" // Force JSON mode if supported by model version
        ]
    ];

    $ch = curl_init("[https://generativelanguage.googleapis.com/v1beta/models/models/gemini-flash-lite-latest:generateContent?key=](https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=)" . $apiKey);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        return ['error' => 'Curl error: ' . curl_error($ch)];
    }
    curl_close($ch);
    
    $jsonResponse = json_decode($response, true);

    // Extract text from Gemini response structure
    if (isset($jsonResponse['candidates'][0]['content']['parts'][0]['text'])) {
        $rawText = $jsonResponse['candidates'][0]['content']['parts'][0]['text'];
        
        // Sanitize and Parse
        $cleanText = cleanAIJson($rawText);
        $parsedData = json_decode($cleanText, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $parsedData;
        } else {
            // Fallback if JSON fails (Log this in production)
            return ['error' => 'AI output was not valid JSON', 'raw' => $rawText];
        }
    }

    return ['error' => 'Invalid API Response structure'];
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

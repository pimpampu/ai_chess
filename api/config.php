<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$envPath = __DIR__ . '/../';
if (!file_exists($envPath . '.env')) {
    error_log("ERROR: .env file not found at: " . $envPath . '.env');
    throw new Exception('.env file not found');
}
$dotenv = Dotenv::createImmutable($envPath);
$envVars = $dotenv->load();
foreach ($envVars as $key => $value) {
    putenv("$key=$value");
}

return [
    // API endpoints for different providers
    'api_endpoints' => [
        'openai' => 'https://api.openai.com/v1/chat/completions',
        'deepseek' => 'https://api.deepseek.com/v1/chat/completions',
        'sonet' => 'https://api.anthropic.com/v1/messages',
        'gemini' => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-001:generateContent'
    ],
    
    // API keys for different providers - loaded from environment variables
    'api_keys' => [
        'openai' => getenv('OPENAI_API_KEY'),
        'deepseek' => getenv('DEEPSEEK_API_KEY'),
        'sonet' => getenv('SONET_API_KEY'),
        'gemini' => getenv('GEMINI_API_KEY'),
    ],
    
    // Model to provider mapping
    'model_providers' => [
        'gpt-3.5-turbo' => 'openai',
        'gpt-4' => 'openai',
        'deepseek-v3' => 'deepseek',
        'deepseek-r1' => 'deepseek',
        'sonet-3.5' => 'sonet',
        'gemini-2.0-flash-001' => 'gemini'
    ],
    
    // Default configuration
    'default_model' => 'gpt-3.5-turbo',
    'request_timeout' => 30
];
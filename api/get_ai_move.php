<?php
header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'game.log');

// Add CORS headers for development
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests and clear_log action
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Handle clear log request
if (isset($_GET['action']) && $_GET['action'] === 'clear_log') {
    file_put_contents('game.log', ''); // Clear the file
    sendResponse(['status' => 'success']);
}

// Load configuration
$config = include('config.php');

function sendResponse($data) {
    echo json_encode($data);
    exit;
}

function sendError($message, $isRetryFailure = false) {
    sendResponse([
        'error' => $message,
        'isRetryFailure' => $isRetryFailure
    ]);
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    sendError('Invalid request data');
}

// Validate required fields
if (!isset($data['model']) || !isset($data['fen']) || !isset($data['move_history']) || !isset($data['player_color'])) {
    sendError('Missing required parameters');
}

// Validate player color matches current turn in FEN
$fenParts = explode(' ', $data['fen']);
$currentTurn = $fenParts[1] === 'w' ? 'white' : 'black';
if ($currentTurn !== $data['player_color']) {
    sendError("Wrong player's turn: AI is {$data['player_color']} but FEN shows {$currentTurn}'s turn");
}

// Helper function to get system instructions
function getSystemInstructions($playerColor) {
    if ($playerColor === 'black') {
        return "You are playing as BLACK. Your pieces are lowercase (rnbqkbnrpppppppp) and start at TOP (rows 0-1). " .
               "CRITICAL RULES FOR MOVE SELECTION:" .
               "1) You can ONLY move BLACK pieces (lowercase in FEN)" .
               "2) Black pieces are in rows 0-1 at start:" .
               "   - Row 0: rnbqkbnr (back row)" .
               "   - Row 1: pppppppp (pawns)" .
               "3) NEVER try to move white pieces (UPPERCASE in FEN)" .
               "4) Check the current FEN - only move pieces that exist in their current positions" .
               "5) PIECE MOVEMENT RULES:" .
               "   - PAWNS (p):" .
               "     * Black pawns can ONLY move DOWNWARD (increasing row numbers)" .
               "     * From row 1, can move one or two squares down" .
               "     * After first move, can only move one square down" .
               "     * Can only move diagonally down when capturing" .
               "     * Can NEVER move backwards (decreasing row numbers)" .
               "   - KNIGHTS (n):" .
               "     * Move in L-shape: 2 squares in one direction, then 1 square perpendicular" .
               "     * Can jump over other pieces" .
               "   - BISHOPS (b):" .
               "     * Move any number of squares diagonally" .
               "     * Cannot jump over other pieces" .
               "   - ROOKS (r):" .
               "     * Move any number of squares horizontally or vertically" .
               "     * Cannot jump over other pieces" .
               "   - QUEEN (q):" .
               "     * Move any number of squares in any direction (diagonal, horizontal, vertical)" .
               "     * Cannot jump over other pieces" .
               "   - KING (k):" .
               "     * Move one square in any direction" .
               "6) Must use standard chess notation format (e.g., 'e2e4', 'g1f3') for moves" .
               "7) CRITICAL: Response must be ONLY a JSON object with NO additional text before or after" .
               "8) Double-check your selected piece is BLACK before responding" .
               "9) IMPORTANT: Your explanation must use standard chess notation (e.g., 'Moving pawn from e2 to e4')";
    } else {
        return "You are playing as WHITE. Your pieces are UPPERCASE (RNBQKBNRPPPPPPPP) and start at BOTTOM (rows 6-7). " .
               "CRITICAL RULES FOR MOVE SELECTION:" .
               "1) You can ONLY move WHITE pieces (UPPERCASE in FEN)" .
               "2) White pieces are in rows 6-7 at start:" .
               "   - Row 7: RNBQKBNR (back row)" .
               "   - Row 6: PPPPPPPP (pawns)" .
               "3) NEVER try to move black pieces (lowercase in FEN)" .
               "4) Check the current FEN - only move pieces that exist in their current positions" .
               "5) PIECE MOVEMENT RULES:" .
               "   - PAWNS (P):" .
               "     * White pawns can ONLY move UPWARD (decreasing row numbers)" .
               "     * From row 6, can move one or two squares up" .
               "     * After first move, can only move one square up" .
               "     * Can only move diagonally up when capturing" .
               "     * Can NEVER move backwards (increasing row numbers)" .
               "   - KNIGHTS (N):" .
               "     * Move in L-shape: 2 squares in one direction, then 1 square perpendicular" .
               "     * Can jump over other pieces" .
               "   - BISHOPS (B):" .
               "     * Move any number of squares diagonally" .
               "     * Cannot jump over other pieces" .
               "   - ROOKS (R):" .
               "     * Move any number of squares horizontally or vertically" .
               "     * Cannot jump over other pieces" .
               "   - QUEEN (Q):" .
               "     * Move any number of squares in any direction (diagonal, horizontal, vertical)" .
               "     * Cannot jump over other pieces - MUST have a clear path with no pieces between start and end position" .
               "     * If a piece blocks the path, you CANNOT move through or over it" .
               "   - KING (K):" .
               "     * Move one square in any direction" .
               "6) Must use standard chess notation format (e.g., 'e2e4', 'g1f3') for moves" .
               "7) CRITICAL: Response must be ONLY a JSON object with NO additional text before or after" .
               "8) Double-check your selected piece is WHITE before responding" .
               "9) IMPORTANT: Your explanation must match the actual piece you're moving";
    }
}

// Helper functions for move validation
function isValidCoordinate($coordinate) {
    return is_int($coordinate) && $coordinate >= 0 && $coordinate < 8;
}

function chessNotationToCoordinates($notation) {
    if (strlen($notation) !== 4) {
        throw new Exception("Invalid chess notation length: $notation");
    }

    $fromFile = strtolower($notation[0]);
    $fromRank = intval($notation[1]);
    $toFile = strtolower($notation[2]);
    $toRank = intval($notation[3]);

    if (!preg_match('/^[a-h]$/', $fromFile) || !preg_match('/^[a-h]$/', $toFile) ||
        $fromRank < 1 || $fromRank > 8 || $toRank < 1 || $toRank > 8) {
        throw new Exception("Invalid chess notation format: $notation");
    }

    // Convert file (a-h) to column (0-7)
    $fromCol = ord($fromFile) - ord('a');
    $toCol = ord($toFile) - ord('a');

    // Convert rank (1-8) to row (0-7), flipping the board since our internal representation
    // has row 0 at the top (black's side) and row 7 at the bottom (white's side)
    $fromRow = 8 - $fromRank;
    $toRow = 8 - $toRank;

    return [
        'fromRow' => $fromRow,
        'fromCol' => $fromCol,
        'toRow' => $toRow,
        'toCol' => $toCol
    ];
}

function decodeFEN($fen) {
    error_log("\n=== Processing FEN ===");
    error_log("Input: $fen");
    
    $parts = explode(' ', $fen);
    $board = array_fill(0, 8, array_fill(0, 8, null));
    $rows = explode('/', $parts[0]);
    
    // Process each row into our board array
    for ($fenRowIndex = 0; $fenRowIndex < 8; $fenRowIndex++) {
        $boardRowIndex = $fenRowIndex;
        $col = 0;
        $fenRow = $rows[$fenRowIndex];
        
        for ($j = 0; $j < strlen($fenRow); $j++) {
            $char = $fenRow[$j];
            if (is_numeric($char)) {
                $emptyCount = intval($char);
                for ($k = 0; $k < $emptyCount; $k++) {
                    $board[$boardRowIndex][$col++] = null;
                }
            } else {
                $color = ctype_upper($char) ? 'white' : 'black';
                $piece = strtolower($char);
                $board[$boardRowIndex][$col++] = ['type' => $piece, 'color' => $color];
            }
        }
    }
    
    error_log("\n=== Board State ===");
    $boardStr = "";
    foreach ($board as $rowIndex => $row) {
        $rowPieces = array_map(function($piece) {
            if ($piece === null) return "-";
            return ($piece['color'] === 'white' ? 'W' : 'B') . substr($piece['type'], 0, 1);
        }, $row);
        $boardStr .= sprintf("Row %d: %s\n", $rowIndex, implode(" ", $rowPieces));
    }
    error_log($boardStr);
    
    return [
        'board' => $board,
        'turn' => $parts[1] === 'w' ? 'white' : 'black'
    ];
}

function isPathClear($fromRow, $fromCol, $toRow, $toCol, $board) {
    $rowDir = $fromRow === $toRow ? 0 : ($toRow - $fromRow) / abs($toRow - $fromRow);
    $colDir = $fromCol === $toCol ? 0 : ($toCol - $fromCol) / abs($toCol - $fromCol);

    $currentRow = $fromRow + $rowDir;
    $currentCol = $fromCol + $colDir;

    while ($currentRow !== $toRow || $currentCol !== $toCol) {
        if ($board[$currentRow][$currentCol] !== null) {
            return false;
        }
        $currentRow += $rowDir;
        $currentCol += $colDir;
    }

    return true;
}

function logMove($message, $type = 'info') {
    $prefix = match($type) {
        'success' => '✅ ',
        'error' => '❌ ',
        'info' => '📝 ',
        'start' => "\n=== ",
        'end' => " ===\n",
        default => ''
    };
    error_log($prefix . $message);
}

function validateMove($fromRow, $fromCol, $toRow, $toCol, $gameState, &$errorMessage = '') {
    logMove("Move Validation", 'start');
    logMove(sprintf("From: (%d,%d) To: (%d,%d) [%s's turn]",
        $fromRow, $fromCol, $toRow, $toCol, $gameState['turn']), 'info');
    
    // Basic coordinate validation
    if (!isValidCoordinate($fromRow) || !isValidCoordinate($fromCol) ||
        !isValidCoordinate($toRow) || !isValidCoordinate($toCol)) {
        $errorMessage = "Invalid coordinates: outside board boundaries";
        logMove($errorMessage, 'error');
        return false;
    }

    // Copy board to prevent reference issues
    $board = array_map(function($row) {
        return array_map(function($cell) {
            return $cell;
        }, $row);
    }, $gameState['board']);
    
    $piece = $board[$fromRow][$fromCol] ?? null;
    
    // Check if there's a piece at the starting position
    if (!$piece) {
        $errorMessage = "No piece found at source position";
        logMove($errorMessage, 'error');
        return false;
    }
    logMove(sprintf("Found %s %s", $piece['color'], $piece['type']), 'info');

    // Check if it's the correct player's turn
    if (($piece['color'] === 'white' && $gameState['turn'] !== 'white') ||
        ($piece['color'] === 'black' && $gameState['turn'] !== 'black')) {
        $errorMessage = "Wrong player's turn";
        logMove($errorMessage, 'error');
        return false;
    }

    // Check if destination has a friendly piece
    $destPiece = $board[$toRow][$toCol] ?? null;
    if ($destPiece) {
        logMove(sprintf("Destination has %s %s", $destPiece['color'], $destPiece['type']), 'info');
        if ($destPiece['color'] === $piece['color']) {
            $errorMessage = "Cannot capture own piece";
            logMove($errorMessage, 'error');
            return false;
        }
    }

    // Piece-specific movement validation
    switch ($piece['type']) {
        case 'p': // Pawn
            // White pawns move from row 6 towards row 0, black pawns move from row 1 towards row 7
            $direction = $piece['color'] === 'white' ? -1 : 1;
            $startRow = $piece['color'] === 'white' ? 6 : 1;

            error_log("Pawn move validation:");
            error_log("Color: {$piece['color']}, Direction: $direction");
            error_log("From: ($fromRow,$fromCol), To: ($toRow,$toCol)");
            error_log("Start row: $startRow");
            error_log("Expected destination for two-square move: " . ($fromRow + (2 * $direction)));

            // Moving forward
            if ($fromCol === $toCol && !$destPiece) {
                // One square forward
                if ($toRow === $fromRow + $direction) {
                    error_log("Valid one square forward move");
                    return true;
                }
                // Two squares from start
                if ($fromRow === $startRow &&
                    $toRow === $fromRow + (2 * $direction) &&
                    !$board[$fromRow + $direction][$fromCol]) {
                    error_log("Valid two square forward move from start");
                    return true;
                }
            }
            // Capturing
            else if (abs($fromCol - $toCol) === 1 && $toRow === $fromRow + $direction) {
                if ($destPiece && $destPiece['color'] !== $piece['color']) {
                    error_log("Valid diagonal capture");
                    return true;
                }
            }

            // Add specific feedback for invalid pawn moves
            if ($fromCol === $toCol && $destPiece) {
                $errorMessage = "Invalid pawn move: cannot move forward to an occupied square";
            } else if (abs($fromCol - $toCol) === 1 && !$destPiece) {
                $errorMessage = "Invalid pawn move: can only move diagonally when capturing";
            } else if (abs($fromCol - $toCol) > 1) {
                $errorMessage = "Invalid pawn move: can only move straight forward or one square diagonally";
            } else if ($piece['color'] === 'white' && $toRow > $fromRow) {
                $errorMessage = "Invalid pawn move: white pawns can only move upward (decreasing row numbers)";
            } else if ($piece['color'] === 'black' && $toRow < $fromRow) {
                $errorMessage = "Invalid pawn move: black pawns can only move downward (increasing row numbers)";
            } else {
                $errorMessage = "Invalid pawn move: must move forward to an empty square or diagonally to capture";
            }
            error_log($errorMessage);
            return false;

        case 'r': // Rook
            if ($fromRow !== $toRow && $fromCol !== $toCol) {
                $errorMessage = "Invalid rook move: must move horizontally or vertically";
                error_log($errorMessage);
                return false;
            }
            if (!isPathClear($fromRow, $fromCol, $toRow, $toCol, $board)) {
                $errorMessage = "Invalid rook move: path is blocked";
                error_log($errorMessage);
                return false;
            }
            return true;

        case 'n': // Knight
            $rowDiff = abs($toRow - $fromRow);
            $colDiff = abs($toCol - $fromCol);
            if (($rowDiff === 2 && $colDiff === 1) || ($rowDiff === 1 && $colDiff === 2)) {
                return true;
            }
            $errorMessage = "Invalid knight move: must move in L-shape";
            error_log($errorMessage);
            return false;

        case 'b': // Bishop
            if (abs($toRow - $fromRow) !== abs($toCol - $fromCol)) {
                $errorMessage = "Invalid bishop move: must move diagonally";
                error_log($errorMessage);
                return false;
            }
            if (!isPathClear($fromRow, $fromCol, $toRow, $toCol, $board)) {
                $errorMessage = "Invalid bishop move: path is blocked";
                error_log($errorMessage);
                return false;
            }
            return true;

        case 'q': // Queen
            $isDiagonal = abs($toRow - $fromRow) === abs($toCol - $fromCol);
            $isStraight = $fromRow === $toRow || $fromCol === $toCol;
            if (!$isDiagonal && !$isStraight) {
                $errorMessage = "Invalid queen move: must move diagonally, horizontally, or vertically";
                error_log($errorMessage);
                return false;
            }
            if (!isPathClear($fromRow, $fromCol, $toRow, $toCol, $board)) {
                $errorMessage = "Invalid queen move: path is blocked";
                error_log($errorMessage);
                return false;
            }
            return true;

        case 'k': // King
            $rowDiff = abs($toRow - $fromRow);
            $colDiff = abs($toCol - $fromCol);
            // Normal king move
            if ($rowDiff <= 1 && $colDiff <= 1) {
                return true;
            }
            
            // Check for castling (king moves two squares horizontally)
            if ($rowDiff === 0 && $colDiff === 2) {
                // Verify this is a valid castling attempt
                $isKingside = $toCol > $fromCol;
                $rookCol = $isKingside ? 7 : 0;
                
                // Check if path is clear
                $startCol = min($fromCol, $rookCol) + 1;
                $endCol = max($fromCol, $rookCol);
                for ($col = $startCol; $col < $endCol; $col++) {
                    if ($board[$fromRow][$col] !== null) {
                        $errorMessage = "Invalid castling: path is not clear";
                        error_log($errorMessage);
                        return false;
                    }
                }
                
                // Check if rook is in correct position
                $rook = $board[$fromRow][$rookCol];
                if (!$rook || $rook['type'] !== 'r' || $rook['color'] !== $piece['color']) {
                    $errorMessage = "Invalid castling: rook not in correct position";
                    error_log($errorMessage);
                    return false;
                }
                
                return true;
            }
            
            $errorMessage = "Invalid king move: must move one square in any direction or castle";
            error_log($errorMessage);
            return false;

        default:
            $errorMessage = "Unknown piece type: {$piece['type']}";
            error_log($errorMessage);
            return false;
    }
}

// Create the AI prompt
$prompt = "You are a chess player playing as {$data['player_color']}. Given the current game state in FEN notation, board visualization, and move history,
determine your next move. You MUST move a {$data['player_color']} piece that exists on the current board.

CURRENT BOARD STATE (- means empty square):
";

// Add visual board representation
$gameState = decodeFEN($data['fen']);
$board = $gameState['board'];
for ($row = 0; $row < 8; $row++) {
    $prompt .= "Row $row: ";
    for ($col = 0; $col < 8; $col++) {
        $piece = $board[$row][$col];
        if ($piece === null) {
            $prompt .= "- ";
        } else {
            $prompt .= ($piece['color'] === 'white' ? 'W' : 'B') . substr($piece['type'], 0, 1) . " ";
        }
    }
    $prompt .= "\n";
}

$prompt .= "\nIMPORTANT:
1. Look at the board visualization above - you can ONLY move pieces that actually exist on the board
2. Before choosing a move, verify there is a piece at your chosen starting position (not a -)
3. Review the move history to understand how the game reached this state
4. CRITICAL - Before making a move:
   a) First look at the FEN string and identify what piece type is actually at your chosen starting position
   b) Double-check this piece type matches your intended move (e.g., if you find a bishop, don't try to move it like a knight)
   c) Verify your move follows the movement rules for that specific piece type
   d) Make sure your explanation uses standard chess notation (e.g., 'Moving pawn from e2 to e4')
5. Remember piece movement rules:
   - PAWNS: Can only move forward (up for white, down for black), never backwards
   - KNIGHTS: Move in L-shape (2 squares + 1 perpendicular)
   - BISHOPS: Move diagonally only
   - ROOKS: Move horizontally/vertically only
   - QUEEN: Move in any direction
   - KING: Move one square in any direction
6. CRITICAL: Your response must be ONLY a JSON object with NO additional text before or after, containing:
    - move: string in standard chess notation (e.g., 'e2e4', 'g1f3')
    - explanation: brief explanation using chess notation (e.g., 'Moving piece from e2 to e4 for this reason')
    DO NOT add any text outside the JSON object. The entire response must be valid JSON.

Example response format:
{\"move\":\"e2e4\",\"explanation\":\"Moving piece from x to y for this reason\"}

Current position (FEN): {$data['fen']}

Move history (most recent first):
";

foreach ($data['move_history'] as $move) {
    // Get piece type from the piece information
    $pieceType = $move['piece']['type'] ?? '';
    $pieceSymbol = '';
    if ($pieceType) {
        switch ($pieceType) {
            case 'pawn': $pieceSymbol = ''; break; // No symbol for pawns in notation
            case 'knight': $pieceSymbol = 'N'; break;
            case 'bishop': $pieceSymbol = 'B'; break;
            case 'rook': $pieceSymbol = 'R'; break;
            case 'queen': $pieceSymbol = 'Q'; break;
            case 'king': $pieceSymbol = 'K'; break;
        }
    }
    $prompt .= "- " . ($move['piece']['color'] === 'white' ? 'White' : 'Black') . " played " . $pieceSymbol . $move['notation'] . "\n";
}

$prompt .= "\n\nProvide your next move in standard chess notation (e.g., 'e2e4'):";

try {
    // Determine the provider based on the model
    $provider = $config['model_providers'][$data['model']] ?? null;
    if (!$provider) {
        sendError('Unsupported model');
    }

    // Get provider-specific configuration
    $apiEndpoint = $config['api_endpoints'][$provider] ?? null;
    $apiKey = $config['api_keys'][$provider] ?? null;

    if (!$apiEndpoint || !$apiKey) {
        sendError('Provider configuration not found');
    }

    // Initialize cURL session
    $ch = curl_init();

    // Set cURL options
    $url = $apiEndpoint;
    if ($provider === 'gemini') {
        $url .= '?key=' . $apiKey;
    }
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $config['request_timeout']);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    // Set provider-specific headers
    $headers = ['Content-Type: application/json'];
    if ($provider === 'sonet') {
        $headers[] = 'x-api-key: ' . $apiKey;
        $headers[] = 'anthropic-version: 2023-06-01';
    } else if ($provider !== 'gemini') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    error_log("Making request to: $apiEndpoint");

    // Prepare the request payload based on provider
    $payload = [];
    if ($provider === 'openai') {
        $payload = [
            'model' => $data['model'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => getSystemInstructions($data['player_color'])
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0,
            'max_tokens' => 2000
        ];
    } else if ($provider === 'deepseek') {
        $payload = [
            'model' => $data['model'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => getSystemInstructions($data['player_color'])
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'max_tokens' => 2000,
            'temperature' => 0
        ];
    } else if ($provider === 'sonet') {
        $payload = [
            'model' => 'claude-3-opus-20240229',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'system' => getSystemInstructions($data['player_color']),
            'max_tokens' => 2000,
            'temperature' => 0
        ];
    } else if ($provider === 'gemini') {
        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => getSystemInstructions($data['player_color']) . "\n\n" . $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0,
                'maxOutputTokens' => 2000
            ]
        ];
    }

    // Debug logging
    error_log("Request to {$provider} API:");
    error_log("Payload: " . json_encode($payload, JSON_PRETTY_PRINT));

    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    // Execute the request
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        throw new Exception(curl_error($ch));
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($httpCode !== 200) {
        throw new Exception("API returned HTTP code $httpCode");
    }

    curl_close($ch);

    // Parse the response based on provider
    $result = json_decode($response, true);
    error_log("Raw API Response: " . json_encode($result, JSON_PRETTY_PRINT));
    
    if (!$result) {
        throw new Exception('Failed to parse API response as JSON');
    }

    // Extract content based on provider format
    $moveContent = null;
    if ($provider === 'openai') {
        if (!isset($result['choices'][0]['message']['content'])) {
            throw new Exception('Invalid OpenAI response format: missing choices[0].message.content');
        }
        $moveContent = $result['choices'][0]['message']['content'];
    } else if ($provider === 'deepseek') {
        if (!isset($result['choices'][0]['message']['content'])) {
            throw new Exception('Invalid DeepSeek response format: missing choices[0].message.content');
        }
        $moveContent = $result['choices'][0]['message']['content'];
    } else if ($provider === 'sonet') {
        if (!isset($result['content']) || !is_array($result['content']) || empty($result['content'])) {
            throw new Exception('Invalid Anthropic response format: missing or invalid content field');
        }
        // Extract text content from the first content item
        if (!isset($result['content'][0]['text'])) {
            throw new Exception('Invalid Anthropic response format: missing text field in content');
        }
        // Remove any "Here is my move:" prefix and trim whitespace
        $moveContent = preg_replace('/^Here is my move:\s*\n*/i', '', trim($result['content'][0]['text']));
        // Ensure we have a valid JSON object
        if (!preg_match('/^\{.*\}$/s', trim($moveContent))) {
            throw new Exception('Invalid response format: response must be a JSON object');
        }
    } else if ($provider === 'gemini') {
        if (!isset($result['candidates']) || !is_array($result['candidates']) || empty($result['candidates'])) {
            throw new Exception('Invalid Gemini response format: missing or invalid candidates field');
        }
        if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            throw new Exception('Invalid Gemini response format: missing text in content parts');
        }
        $moveContent = trim($result['candidates'][0]['content']['parts'][0]['text']);
        // Remove markdown code blocks if present
        $moveContent = preg_replace('/^```(json)?\n/', '', $moveContent);
        $moveContent = preg_replace('/```$/', '', $moveContent);
        $moveContent = trim($moveContent);
        // Ensure we have a valid JSON object
        if (!preg_match('/^\{.*\}$/s', trim($moveContent))) {
            throw new Exception('Invalid response format: response must be a JSON object');
        }
    }

    if (!$moveContent) {
        throw new Exception('No valid move content found in response');
    }

    // Log complete AI response and game state for debugging
    error_log("AI Response - Raw Content: " . $moveContent);
    error_log("Current FEN: " . $data['fen']);
    error_log("Model Used: " . $data['model']);


    // Parse the JSON response
    $moveData = json_decode(trim($moveContent), true);
    
    if (!$moveData || !isset($moveData['move']) || !isset($moveData['explanation'])) {
        throw new Exception("Invalid JSON response format from AI. Expected 'move' and 'explanation' fields.");
    }
    
    $moveStr = $moveData['move'];
    $explanation = $moveData['explanation'];
    
    // Strip any piece letters (N,B,R,Q,K) from the move string
    $moveStr = preg_replace('/^[NBRQK]/', '', $moveStr);
    
    // Check for exact format compliance of chess notation
    if (!preg_match('/^[a-h][1-8][a-h][1-8]$/', $moveStr)) {
        throw new Exception("Invalid move format from AI. Got: '$moveStr', Expected format: 'e2e4'");
    }
    
    try {
        $coords = chessNotationToCoordinates($moveStr);
    } catch (Exception $e) {
        throw new Exception("Failed to parse chess notation: " . $e->getMessage());
    }

    // Log the parsed move for debugging
    error_log("AI Move - From: ({$coords['fromRow']},{$coords['fromCol']}) To: ({$coords['toRow']},{$coords['toCol']})");
    
    // Decode the current game state
    $gameState = decodeFEN($data['fen']);

    // Log coordinates and game state before validation
    error_log("Coordinates before validation: " . json_encode($coords));
    error_log("Game state before validation: " . json_encode($gameState));
    
    // Validate the move using original top-down coordinates
    $errorMessage = '';
    $moveValid = validateMove($coords['fromRow'], $coords['fromCol'], $coords['toRow'], $coords['toCol'], $gameState, $errorMessage);
    if ($moveValid === false) {
        // Create a retry prompt with feedback about the invalid move
        $retryPrompt = $prompt . "\n\nYour previous move {$moveStr} was invalid: {$errorMessage}\n\n";
        
        $retryPrompt .= "\n\nPlease try again with a valid move. Your response must be ONLY a JSON object with NO additional text before or after, containing:
        - move: string in standard chess notation (e.g., 'e2e4', 'g1f3')
        - explanation: brief explanation using chess notation (e.g., 'Moving piece from e2 to e4 for this reason')
        DO NOT add any text outside the JSON object. The entire response must be valid JSON.

        Example response format:
        {\"move\":\"e2e4\",\"explanation\":\"Moving pawn from x to y to control center\"}";
        
        // Make another API request with the retry prompt
        $retryPayload = $payload;
        if ($provider === 'openai') {
            $retryPayload['messages'][1]['content'] = $retryPrompt;
        } else if ($provider === 'deepseek') {
            $retryPayload['messages'][1]['content'] = $retryPrompt;
        } else if ($provider === 'sonet') {
            $retryPayload['messages'][0]['content'] = $retryPrompt;
        } else if ($provider === 'gemini') {
            $retryPayload['contents'][0]['parts'][0]['text'] = getSystemInstructions($data['player_color']) . "\n\n" . $retryPrompt;
        }
        
        // Log the retry attempt
        error_log("\n=== Retrying with feedback ===");
        error_log("Invalid move feedback: " . $retryPrompt);
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($retryPayload));
        $retryResponse = curl_exec($ch);
        
        if (curl_errno($ch)) {
            throw new Exception(curl_error($ch));
        }
        
        $retryResult = json_decode($retryResponse, true);
        error_log("Retry API Response: " . json_encode($retryResult, JSON_PRETTY_PRINT));
        
        // Extract retry move content based on provider
        $retryMoveContent = null;
        if ($provider === 'openai') {
            if (!isset($retryResult['choices'][0]['message']['content'])) {
                throw new Exception('Invalid OpenAI retry response format');
            }
            $retryMoveContent = $retryResult['choices'][0]['message']['content'];
        } else if ($provider === 'deepseek') {
            if (!isset($retryResult['choices'][0]['message']['content'])) {
                throw new Exception('Invalid DeepSeek retry response format');
            }
            $retryMoveContent = $retryResult['choices'][0]['message']['content'];
        } else if ($provider === 'sonet') {
            if (!isset($retryResult['content'][0]['text'])) {
                throw new Exception('Invalid Anthropic retry response format');
            }
            $retryMoveContent = $retryResult['content'][0]['text'];
        } else if ($provider === 'gemini') {
            if (!isset($retryResult['candidates']) || !is_array($retryResult['candidates']) || empty($retryResult['candidates'])) {
                throw new Exception('Invalid Gemini retry response format: missing or invalid candidates field');
            }
            if (!isset($retryResult['candidates'][0]['content']['parts'][0]['text'])) {
                throw new Exception('Invalid Gemini retry response format: missing text in content parts');
            }
            $retryMoveContent = trim($retryResult['candidates'][0]['content']['parts'][0]['text']);
            // Ensure we have a valid JSON object
            if (!preg_match('/^\{.*\}$/s', trim($retryMoveContent))) {
                throw new Exception('Invalid retry response format: response must be a JSON object');
            }
        }
        
        if (!$retryMoveContent) {
            throw new Exception('No valid move content found in retry response');
        }
        
        // Parse and validate the retry move
        $retryMoveData = json_decode(trim($retryMoveContent), true);
        if (!$retryMoveData || !isset($retryMoveData['move']) || !isset($retryMoveData['explanation'])) {
            throw new Exception('Invalid retry move format');
        }
        
        $retryMoveStr = $retryMoveData['move'];
        if (!preg_match('/^[a-h][1-8][a-h][1-8]$/', $retryMoveStr)) {
            throw new Exception('Invalid retry move notation');
        }
        
        $retryCoords = chessNotationToCoordinates($retryMoveStr);
        $retryErrorMessage = '';
        $retryMoveValid = validateMove($retryCoords['fromRow'], $retryCoords['fromCol'],
                                      $retryCoords['toRow'], $retryCoords['toCol'], $gameState, $retryErrorMessage);
        
        if ($retryMoveValid === false) {
            // Return error but indicate this was a second failure
            sendError('AI suggested another invalid move after feedback', true);
        }
        
        // Send the validated retry move back to the client
        sendResponse([
            'move' => [
                'fromRow' => $retryCoords['fromRow'],
                'fromCol' => $retryCoords['fromCol'],
                'toRow' => $retryCoords['toRow'],
                'toCol' => $retryCoords['toCol']
            ],
            'explanation' => $retryMoveData['explanation']
        ]);
    }

    // If original move was valid, send it back to the client
    sendResponse([
        'move' => [
            'fromRow' => $coords['fromRow'],
            'fromCol' => $coords['fromCol'],
            'toRow' => $coords['toRow'],
            'toCol' => $coords['toCol']
        ],
        'explanation' => $explanation
    ]);

} catch (Exception $e) {
    sendError($e->getMessage());
}
?>

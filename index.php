<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Chess Battle</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <h1>AI Chess Battle</h1>
        <div class="game-info">
            <div class="player">
                <h3>Player 2 (Black) <span class="position-indicator">Top</span></h3>
                <div class="ai-info">
                    <select id="player2-model" class="ai-model-select">
                        <option value="gpt-3.5-turbo">GPT-3.5 Turbo</option>
                        <option value="gpt-4">GPT-4</option>
                        <option value="deepseek-v3">DeepSeek v3</option>
                        <option value="deepseek-r1">DeepSeek r1</option>
                        <option value="sonet-3.5">Sonet 3.5</option>
                        <option value="gemini-2.0-flash-001">gemini-2.0-flash-001</option>
                    </select>
                    <div class="mistake-counter">
                        Mistakes: <span id="black-mistakes">0</span>
                    </div>
                </div>
            </div>
            <div class="player">
                <h3>Player 1 (White) <span class="position-indicator">Bottom</span></h3>
                <div class="ai-info">
                    <select id="player1-model" class="ai-model-select">
                        <option value="gpt-3.5-turbo">GPT-3.5 Turbo</option>
                        <option value="gpt-4">GPT-4</option>
                        <option value="deepseek-v3">DeepSeek v3</option>
                        <option value="deepseek-r1">DeepSeek r1</option>
                        <option value="sonet-3.5">Sonet 3.5</option>
                        <option value="gemini-2.0-flash-001">gemini-2.0-flash-001</option>
                    </select>
                    <div class="mistake-counter">
                        Mistakes: <span id="white-mistakes">0</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="game-controls">
            <button id="start-game">Start Game</button>
            <button id="pause-game">Pause</button>
            <button id="reset-game">Reset</button>
        </div>
        <div class="game-board-container">
            <div id="captured-pieces-black" class="captured-pieces"></div>
            <div id="chessboard" class="chessboard"></div>
            <div id="captured-pieces-white" class="captured-pieces"></div>
        </div>
        <div id="game-log" class="game-log">
            <h3>Game Log</h3>
            <div id="log-content"></div>
        </div>
    </div>
    <script src="js/chess.js?<? echo time() ?>"></script>
    <script src="js/game.js?<? echo time() ?>"></script>
</body>
</html>
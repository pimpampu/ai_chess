document.addEventListener('DOMContentLoaded', () => {
    const chessGame = new Chess();
    let selectedSquare = null;
    let isAIThinking = false;

    const chessPieces = {
        'white': {
            'king': '♔',
            'queen': '♕',
            'rook': '♖',
            'bishop': '♗',
            'knight': '♘',
            'pawn': '♙'
        },
        'black': {
            'king': '♚',
            'queen': '♛',
            'rook': '♜',
            'bishop': '♝',
            'knight': '♞',
            'pawn': '♟'
        }
    };

    function updateActivePlayer() {
        const player1 = document.querySelector('.player:nth-child(2)'); // White (bottom)
        const player2 = document.querySelector('.player:nth-child(1)'); // Black (top)
        const currentPlayer = chessGame.getCurrentPlayer();

        player1.classList.toggle('active', currentPlayer === 'white');
        player2.classList.toggle('active', currentPlayer === 'black');
    }

    function initializeBoard() {
        const chessboard = document.getElementById('chessboard');
        chessboard.innerHTML = '';
        updateCapturedPieces();

        for (let row = 0; row < 8; row++) {
            for (let col = 0; col < 8; col++) {
                const square = document.createElement('div');
                square.className = `square ${(row + col) % 2 === 0 ? 'white' : 'black'}`;
                square.dataset.row = row;
                square.dataset.col = col;
                square.addEventListener('click', handleSquareClick);
                
                const piece = chessGame.getPieceAt(row, col);
                if (piece) {
                    square.innerHTML = `<span class="piece">${chessPieces[piece.color][piece.type]}</span>`;
                }
                
                chessboard.appendChild(square);
            }
        }
        updateActivePlayer();
    }

    function handleSquareClick(event) {
        if (isAIThinking || chessGame.getGameStatus() !== 'playing') {
            // If game is ended, show a message when trying to move
            if (chessGame.getGameStatus() === 'ended') {
                const lastMove = chessGame.getLastMove();
                if (lastMove && lastMove.checkmate) {
                    logError(`Game Over! ${lastMove.piece.color === 'white' ? 'Black' : 'White'} has won by checkmate!`);
                }
            }
            return;
        }

        const square = event.target.closest('.square');
        const row = parseInt(square.dataset.row);
        const col = parseInt(square.dataset.col);

        if (selectedSquare) {
            const selectedRow = parseInt(selectedSquare.dataset.row);
            const selectedCol = parseInt(selectedSquare.dataset.col);
            
            if (row === selectedRow && col === selectedCol) {
                // Deselect the square
                selectedSquare.classList.remove('selected');
                selectedSquare = null;
            } else {
                // Check for capture before moving
                const capturedPiece = chessGame.getPieceAt(row, col);
                
                // Attempt to move
                if (chessGame.movePiece(selectedRow, selectedCol, row, col)) {
                    const toSquare = square;
                    animatePieceMovement(selectedSquare, toSquare, capturedPiece).then(() => {
                        updateBoard();
                        updateCapturedPieces();
                        logMove(chessGame.getLastMove());
                        selectedSquare.classList.remove('selected');
                        selectedSquare = null;
                        updateActivePlayer();
                        requestAIMove();
                    });
                }
            }
        } else {
            const piece = chessGame.getPieceAt(row, col);
            if (piece && piece.color === chessGame.getCurrentPlayer()) {
                square.classList.add('selected');
                selectedSquare = square;
            }
        }
    }

    function updateCapturedPieces() {
        const moveHistory = chessGame.getMoveHistory();
        const capturedPieces = {
            white: [],
            black: []
        };

        // Collect all captured pieces from move history
        moveHistory.forEach(move => {
            if (move.captured) {
                const capturedColor = move.captured.color;
                capturedPieces[capturedColor].push(move.captured);
            }
        });

        // Update the display for both sides
        ['white', 'black'].forEach(color => {
            const container = document.getElementById(`captured-pieces-${color}`);
            container.innerHTML = '';
            
            // Sort pieces by type for consistent display
            const sortOrder = { 'queen': 1, 'rook': 2, 'bishop': 3, 'knight': 4, 'pawn': 5 };
            capturedPieces[color].sort((a, b) => sortOrder[a.type] - sortOrder[b.type]);
            
            capturedPieces[color].forEach(piece => {
                const pieceElement = document.createElement('div');
                pieceElement.className = 'captured-piece';
                pieceElement.textContent = chessPieces[piece.color][piece.type];
                container.appendChild(pieceElement);
            });
        });
    }

    function animatePieceMovement(fromSquare, toSquare, capturedPiece = null) {
        return new Promise(resolve => {
            const piece = fromSquare.querySelector('.piece');
            if (!piece) {
                resolve();
                return;
            }

            const promises = [];

            // Animate the moving piece
            const fromRect = fromSquare.getBoundingClientRect();
            const toRect = toSquare.getBoundingClientRect();
            const boardRect = document.getElementById('chessboard').getBoundingClientRect();

            // Calculate relative positions
            const fromX = fromRect.left - boardRect.left;
            const fromY = fromRect.top - boardRect.top;
            const toX = toRect.left - boardRect.left;
            const toY = toRect.top - boardRect.top;

            // Create animated clone for moving piece
            const clone = piece.cloneNode(true);
            clone.classList.add('animating');
            document.getElementById('chessboard').appendChild(clone);

            // Position clone at start
            clone.style.transform = `translate(${fromX}px, ${fromY}px)`;
            clone.offsetHeight; // Force reflow

            // Animate to destination
            clone.style.transform = `translate(${toX}px, ${toY}px)`;

            promises.push(new Promise(resolveMove => {
                clone.addEventListener('transitionend', () => {
                    clone.remove();
                    resolveMove();
                }, { once: true });
            }));

            // If there's a captured piece, animate it to the captured pieces container
            if (capturedPiece) {
                const capturedPieceElement = toSquare.querySelector('.piece');
                if (capturedPieceElement) {
                    const capturedClone = capturedPieceElement.cloneNode(true);
                    capturedClone.classList.add('animating');
                    document.getElementById('chessboard').appendChild(capturedClone);

                    // Position at capture location
                    capturedClone.style.transform = `translate(${toX}px, ${toY}px)`;
                    capturedClone.offsetHeight; // Force reflow

                    // Get the captured pieces container position
                    const containerSelector = `#captured-pieces-${capturedPiece.color}`;
                    const container = document.querySelector(containerSelector);
                    const containerRect = container.getBoundingClientRect();

                    // Calculate destination in the captured pieces container
                    const capturedX = containerRect.left - boardRect.left;
                    const capturedY = containerRect.top - boardRect.top + (container.children.length * 30);

                    // Animate to captured pieces container
                    capturedClone.style.transform = `translate(${capturedX}px, ${capturedY}px)`;

                    promises.push(new Promise(resolveCapture => {
                        capturedClone.addEventListener('transitionend', () => {
                            capturedClone.remove();
                            resolveCapture();
                        }, { once: true });
                    }));
                }
            }

            // Wait for all animations to complete
            Promise.all(promises).then(resolve);
        });
    }

    function updateBoard() {
        const squares = document.querySelectorAll('.square');
        squares.forEach(square => {
            const row = parseInt(square.dataset.row);
            const col = parseInt(square.dataset.col);
            const piece = chessGame.getPieceAt(row, col);
            
            square.innerHTML = piece ?
                `<span class="piece">${chessPieces[piece.color][piece.type]}</span>` : '';
        });
    }

    function updateMistakeCounters() {
        const mistakes = chessGame.getAllMistakes();
        document.getElementById('white-mistakes').textContent = mistakes.white;
        document.getElementById('black-mistakes').textContent = mistakes.black;
    }

    async function requestAIMove() {
        isAIThinking = true;
        
        // Disable controls while AI is thinking
        document.getElementById('start-game').disabled = true;
        document.getElementById('pause-game').disabled = true;
        document.getElementById('player1-model').disabled = true;
        document.getElementById('player2-model').disabled = true;
        
        const currentPlayer = chessGame.getCurrentPlayer();
        const modelSelect = document.getElementById(`player${currentPlayer === 'white' ? '1' : '2'}-model`);
        const model = modelSelect.value;
        
        logMove({ piece: { color: currentPlayer }, notation: `AI (${model}) is thinking...` });

        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 30000); // 30 second timeout

        try {
            const response = await fetch('api/get_ai_move.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    model: model,
                    fen: chessGame.getFEN(),
                    move_history: chessGame.getMoveHistory(),
                    player_color: currentPlayer
                }),
                signal: controller.signal
            });

            clearTimeout(timeoutId);

            if (!response.ok) {
                const errorText = await response.text();
                throw new Error(`API Error (${response.status}): ${errorText}`);
            }
            
            const data = await response.json();
            if (data.error) {
                // Increment mistake counter for the current player
                chessGame.addMistake(currentPlayer);
                updateMistakeCounters();
                
                // If this is a retry failure, it means two consecutive failures
                if (data.isRetryFailure) {
                    const winningPlayer = currentPlayer === 'white' ? 'Black' : 'White';
                    logError(`Game Over! ${winningPlayer} wins due to ${currentPlayer} model failing twice in a row!`);
                    chessGame.setGameStatus('ended');
                    return;
                }
                
                logError(`API Error: ${data.error}`);
                throw new Error(data.error);
            }

            const move = data.move;
            const squares = document.querySelectorAll('.square');
            const fromSquare = Array.from(squares).find(square =>
                parseInt(square.dataset.row) === move.fromRow &&
                parseInt(square.dataset.col) === move.fromCol
            );
            const toSquare = Array.from(squares).find(square =>
                parseInt(square.dataset.row) === move.toRow &&
                parseInt(square.dataset.col) === move.toCol
            );

            // Check for capture before moving
            const capturedPiece = chessGame.getPieceAt(move.toRow, move.toCol);
            
            if (chessGame.movePiece(move.fromRow, move.fromCol, move.toRow, move.toCol)) {
                if (fromSquare && toSquare) {
                    animatePieceMovement(fromSquare, toSquare, capturedPiece).then(() => {
                        updateBoard();
                        updateCapturedPieces();
                        updateActivePlayer();
                        const lastMove = chessGame.getLastMove();
                        logMove(lastMove, data.explanation);
                        
                        // Check if the next player is also AI-controlled
                        const nextPlayer = chessGame.getCurrentPlayer();
                        const nextPlayerModel = document.getElementById(`player${nextPlayer === 'white' ? '1' : '2'}-model`).value;
                        if (nextPlayerModel !== 'human' && chessGame.getGameStatus() === 'playing') {
                            requestAIMove();
                        }
                    });
                }
            }
        } catch (error) {
            console.error('Error getting AI move:', error);
            if (error.name === 'AbortError') {
                logError('AI Error: Request timed out after 30 seconds');
            } else {
                logError(`AI Error: ${error.message}`);
            }
            chessGame.setGameStatus('waiting'); // Reset game state on error
        } finally {
            clearTimeout(timeoutId);
            isAIThinking = false;
            // Re-enable controls
            document.getElementById('start-game').disabled = false;
            document.getElementById('pause-game').disabled = false;
            document.getElementById('player1-model').disabled = false;
            document.getElementById('player2-model').disabled = false;
        }
    }

    function logMove(move, explanation = null) {
        const logContent = document.getElementById('log-content');
        const entry = document.createElement('div');
        entry.className = 'log-entry';
        
        // Create move text
        const moveText = document.createElement('div');
        let pieceSymbol = '';
        if (move.piece.type && move.piece.type !== 'pawn') { // No symbol for pawns
            pieceSymbol = move.piece.type === 'knight' ? 'N' : move.piece.type[0].toUpperCase();
        }
        moveText.textContent = `${move.piece.color === 'white' ? 'White' : 'Black'}: ${pieceSymbol}${move.notation}`;
        entry.appendChild(moveText);
        
        // Add explanation if provided
        if (explanation) {
            const explanationText = document.createElement('div');
            explanationText.className = 'move-explanation';
            explanationText.textContent = `Reasoning: ${explanation}`;
            explanationText.style.fontSize = '0.9em';
            explanationText.style.color = '#666';
            explanationText.style.marginLeft = '10px';
            entry.appendChild(explanationText);
        }

        // Add checkmate or check announcement
        if (move.checkmate) {
            const checkmateText = document.createElement('div');
            checkmateText.className = 'checkmate-announcement';
            checkmateText.textContent = `Checkmate! ${move.piece.color === 'white' ? 'Black' : 'White'} wins!`;
            checkmateText.style.color = '#d32f2f';
            checkmateText.style.fontWeight = 'bold';
            checkmateText.style.marginTop = '5px';
            entry.appendChild(checkmateText);
        } else if (move.check) {
            const checkText = document.createElement('div');
            checkText.className = 'check-announcement';
            checkText.textContent = 'Check!';
            checkText.style.color = '#f57c00';
            checkText.style.fontWeight = 'bold';
            checkText.style.marginTop = '5px';
            entry.appendChild(checkText);
        }
        
        logContent.appendChild(entry);
        logContent.scrollTop = logContent.scrollHeight;
    }

    function logError(message) {
        const logContent = document.getElementById('log-content');
        const entry = document.createElement('div');
        entry.className = 'log-entry error';
        entry.style.color = 'red';
        entry.textContent = message;
        logContent.appendChild(entry);
        logContent.scrollTop = logContent.scrollHeight;
    }

    // Event Listeners for game controls
    document.getElementById('start-game').addEventListener('click', async () => {
        if (chessGame.getGameStatus() === 'waiting' || chessGame.getGameStatus() === 'ended') {
            // Clear PHP error log
            try {
                await fetch('api/get_ai_move.php?action=clear_log');
            } catch (error) {
                console.error('Failed to clear error log:', error);
            }

            // Reset game state and UI
            chessGame.board = chessGame.initializeBoard();
            chessGame.currentPlayer = 'white';
            chessGame.moveHistory = [];
            chessGame.castlingRights = {
                white: { kingside: true, queenside: true },
                black: { kingside: true, queenside: true }
            };
            chessGame.enPassantTarget = '-';
            chessGame.halfMoveClock = 0;
            chessGame.fullMoveNumber = 1;
            chessGame.mistakes = { white: 0, black: 0 };
            chessGame.setGameStatus('playing');
            document.getElementById('log-content').innerHTML = '';
            document.getElementById('white-mistakes').textContent = '0';
            document.getElementById('black-mistakes').textContent = '0';
            initializeBoard();
            if (chessGame.getCurrentPlayer() === 'white') {
                requestAIMove();
            }
        }
    });

    document.getElementById('pause-game').addEventListener('click', () => {
        const currentStatus = chessGame.getGameStatus();
        if (currentStatus === 'playing') {
            chessGame.setGameStatus('paused');
        } else if (currentStatus === 'paused') {
            chessGame.setGameStatus('playing');
            if (!isAIThinking) {
                requestAIMove();
            }
        }
    });

    document.getElementById('reset-game').addEventListener('click', () => {
        // Reset game state
        chessGame.board = chessGame.initializeBoard();
        chessGame.currentPlayer = 'white';
        chessGame.moveHistory = [];
        chessGame.castlingRights = {
            white: { kingside: true, queenside: true },
            black: { kingside: true, queenside: true }
        };
        chessGame.enPassantTarget = '-';
        chessGame.halfMoveClock = 0;
        chessGame.fullMoveNumber = 1;
        chessGame.mistakes = { white: 0, black: 0 };
        chessGame.setGameStatus('waiting');
        
        // Reset UI
        document.getElementById('log-content').innerHTML = '';
        document.getElementById('white-mistakes').textContent = '0';
        document.getElementById('black-mistakes').textContent = '0';
        initializeBoard();
    });

    // Initial board setup
    initializeBoard();
});

class Chess {
    constructor() {
        this.board = this.initializeBoard();
        this.currentPlayer = 'white';
        this.moveHistory = [];
        this.gameStatus = 'waiting'; // waiting, playing, paused, ended
        this.castlingRights = {
            white: { kingside: true, queenside: true },
            black: { kingside: true, queenside: true }
        };
        this.enPassantTarget = '-';
        this.halfMoveClock = 0;
        this.fullMoveNumber = 1;
        this.mistakes = {
            white: 0,
            black: 0
        };
    }

    initializeBoard() {
        const board = new Array(8).fill(null).map(() => new Array(8).fill(null));

        // Set up pieces in standard chess formation
        const setupPieces = (row, pawnRow, color) => {
            const pieces = ['rook', 'knight', 'bishop', 'queen', 'king', 'bishop', 'knight', 'rook'];
            // Set up main pieces
            pieces.forEach((type, col) => {
                board[row][col] = { type, color, position: [row, col] };
            });
            // Set up pawns
            for (let col = 0; col < 8; col++) {
                board[pawnRow][col] = { type: 'pawn', color, position: [pawnRow, col] };
            }
        };

        // Set up black pieces at top (rows 0-1)
        setupPieces(0, 1, 'black');
        
        // Set up white pieces at bottom (rows 6-7)
        setupPieces(7, 6, 'white');

        return board;
    }

    getPieceAt(row, col) {
        return this.board[row][col];
    }

    movePiece(fromRow, fromCol, toRow, toCol) {
        const piece = this.board[fromRow][fromCol];
        if (!piece) return false;

        if (!this.isValidMove(fromRow, fromCol, toRow, toCol)) return false;

        // Make a temporary move to check if it would put own king in check
        const tempBoard = this.board.map(row => [...row]);
        this.board[toRow][toCol] = { ...piece, position: [toRow, toCol] };
        this.board[fromRow][fromCol] = null;

        const isOwnKingInCheck = this.isKingInCheck(piece.color);

        // Restore the board
        this.board = tempBoard;

        if (isOwnKingInCheck) return false;

        const move = {
            piece: { ...piece },
            from: [fromRow, fromCol],
            to: [toRow, toCol],
            captured: this.board[toRow][toCol],
            notation: this.getMoveNotation(piece, fromRow, fromCol, toRow, toCol)
        };

        // Update castling rights
        if (piece.type === 'king') {
            this.castlingRights[piece.color].kingside = false;
            this.castlingRights[piece.color].queenside = false;
        } else if (piece.type === 'rook') {
            if (fromCol === 0) { // Queenside rook
                this.castlingRights[piece.color].queenside = false;
            } else if (fromCol === 7) { // Kingside rook
                this.castlingRights[piece.color].kingside = false;
            }
        }

        // Update en passant target
        if (piece.type === 'pawn' && Math.abs(toRow - fromRow) === 2) {
            const enPassantRow = (fromRow + toRow) / 2;
            this.enPassantTarget = `${String.fromCharCode(97 + toCol)}${enPassantRow + 1}`;
        } else {
            this.enPassantTarget = '-';
        }

        // Update halfmove clock
        if (piece.type === 'pawn' || move.captured) {
            this.halfMoveClock = 0;
        } else {
            this.halfMoveClock++;
        }

        // Handle castling move
        if (piece.type === 'king' && Math.abs(toCol - fromCol) === 2) {
            // Determine if this is kingside or queenside castling
            const isKingside = toCol > fromCol;
            const rookFromCol = isKingside ? 7 : 0;
            const rookToCol = isKingside ? toCol - 1 : toCol + 1;
            
            // Move the rook
            const rook = this.board[fromRow][rookFromCol];
            this.board[fromRow][rookToCol] = { ...rook, position: [fromRow, rookToCol] };
            this.board[fromRow][rookFromCol] = null;
            
            // Update move notation to indicate castling
            move.notation = isKingside ? 'O-O' : 'O-O-O';
        }

        // Update piece position
        this.board[toRow][toCol] = { ...piece, position: [toRow, toCol] };
        this.board[fromRow][fromCol] = null;

        this.moveHistory.push(move);
        this.currentPlayer = this.currentPlayer === 'white' ? 'black' : 'white';

        // Update full move number
        if (this.currentPlayer === 'white') {
            this.fullMoveNumber++;
        }

        // Check if the move resulted in checkmate
        if (this.isCheckmate(this.currentPlayer)) {
            this.gameStatus = 'ended';
            move.notation += '#'; // Add checkmate symbol to notation
            move.checkmate = true;
        } else if (this.isKingInCheck(this.currentPlayer)) {
            move.notation += '+'; // Add check symbol to notation
            move.check = true;
        }

        return true;
    }

    isValidMove(fromRow, fromCol, toRow, toCol) {
        const piece = this.board[fromRow][fromCol];
        if (!piece || piece.color !== this.currentPlayer) return false;

        // Basic boundary checks
        if (toRow < 0 || toRow > 7 || toCol < 0 || toCol > 7) return false;

        // Can't capture own piece
        const targetPiece = this.board[toRow][toCol];
        if (targetPiece && targetPiece.color === piece.color) return false;

        // Piece-specific move validation
        switch (piece.type) {
            case 'pawn':
                return this.isValidPawnMove(fromRow, fromCol, toRow, toCol);
            case 'rook':
                return this.isValidRookMove(fromRow, fromCol, toRow, toCol);
            case 'knight':
                return this.isValidKnightMove(fromRow, fromCol, toRow, toCol);
            case 'bishop':
                return this.isValidBishopMove(fromRow, fromCol, toRow, toCol);
            case 'queen':
                return this.isValidQueenMove(fromRow, fromCol, toRow, toCol);
            case 'king':
                return this.isValidKingMove(fromRow, fromCol, toRow, toCol);
        }
        return false;
    }

    isValidPawnMove(fromRow, fromCol, toRow, toCol) {
        const piece = this.board[fromRow][fromCol];
        const direction = piece.color === 'white' ? -1 : 1; // White moves up (-1), black moves down (+1)
        const startRow = piece.color === 'white' ? 6 : 1; // White starts on rank 7, black on rank 2

        // Moving forward
        if (fromCol === toCol && !this.board[toRow][toCol]) {
            if (toRow === fromRow + direction) return true;
            if (fromRow === startRow && toRow === fromRow + 2 * direction && !this.board[fromRow + direction][fromCol]) return true;
        }

        // Capturing
        if (Math.abs(fromCol - toCol) === 1 && toRow === fromRow + direction) {
            return this.board[toRow][toCol] && this.board[toRow][toCol].color !== piece.color;
        }

        return false;
    }

    isValidRookMove(fromRow, fromCol, toRow, toCol) {
        if (fromRow !== toRow && fromCol !== toCol) return false;
        return this.isPathClear(fromRow, fromCol, toRow, toCol);
    }

    isValidKnightMove(fromRow, fromCol, toRow, toCol) {
        const rowDiff = Math.abs(toRow - fromRow);
        const colDiff = Math.abs(toCol - fromCol);
        return (rowDiff === 2 && colDiff === 1) || (rowDiff === 1 && colDiff === 2);
    }

    isValidBishopMove(fromRow, fromCol, toRow, toCol) {
        if (Math.abs(toRow - fromRow) !== Math.abs(toCol - fromCol)) return false;
        return this.isPathClear(fromRow, fromCol, toRow, toCol);
    }

    isValidQueenMove(fromRow, fromCol, toRow, toCol) {
        return this.isValidRookMove(fromRow, fromCol, toRow, toCol) || 
               this.isValidBishopMove(fromRow, fromCol, toRow, toCol);
    }

    isValidKingMove(fromRow, fromCol, toRow, toCol) {
        const rowDiff = Math.abs(toRow - fromRow);
        const colDiff = Math.abs(toCol - fromCol);
        
        // Normal king move
        if (rowDiff <= 1 && colDiff <= 1) {
            return true;
        }
        
        // Check for castling
        const piece = this.board[fromRow][fromCol];
        if (!piece || piece.type !== 'king') return false;
        
        // Verify this is a castling attempt (king moves two squares horizontally)
        if (rowDiff === 0 && colDiff === 2) {
            // Check if castling is still allowed for this side
            const isKingside = toCol > fromCol;
            if (!this.castlingRights[piece.color][isKingside ? 'kingside' : 'queenside']) {
                return false;
            }
            
            // Check if the path is clear
            const rookCol = isKingside ? 7 : 0;
            const rookPiece = this.board[fromRow][rookCol];
            if (!rookPiece || rookPiece.type !== 'rook' || rookPiece.color !== piece.color) {
                return false;
            }
            
            // Check if squares between king and rook are empty
            const startCol = Math.min(fromCol, rookCol) + 1;
            const endCol = Math.max(fromCol, rookCol);
            for (let col = startCol; col < endCol; col++) {
                if (this.board[fromRow][col]) {
                    return false;
                }
            }
            
            // Check if king is in check or if squares king moves through are under attack
            const direction = isKingside ? 1 : -1;
            // Store original position
            const originalKingPos = this.board[fromRow][fromCol];
            this.board[fromRow][fromCol] = null;
            
            // Check each square the king moves through
            for (let col = fromCol; col !== toCol + direction; col += direction) {
                this.board[fromRow][col] = originalKingPos;
                if (this.isKingInCheck(piece.color)) {
                    // Restore king position and return false
                    this.board[fromRow][col] = null;
                    this.board[fromRow][fromCol] = originalKingPos;
                    return false;
                }
                this.board[fromRow][col] = null;
            }
            
            // Restore king position
            this.board[fromRow][fromCol] = originalKingPos;
            return true;
        }
        
        return false;
    }

    isPathClear(fromRow, fromCol, toRow, toCol) {
        const rowDir = fromRow === toRow ? 0 : (toRow - fromRow) / Math.abs(toRow - fromRow);
        const colDir = fromCol === toCol ? 0 : (toCol - fromCol) / Math.abs(toCol - fromCol);

        let currentRow = fromRow + rowDir;
        let currentCol = fromCol + colDir;

        while (currentRow !== toRow || currentCol !== toCol) {
            if (this.board[currentRow][currentCol]) return false;
            currentRow += rowDir;
            currentCol += colDir;
        }

        return true;
    }

    getMoveNotation(piece, fromRow, fromCol, toRow, toCol) {
        const files = 'abcdefgh';
        // Always convert to standard chess notation (a1 is bottom-left)
        const getRank = row => 8 - row;  // Convert 0-7 row to 1-8 rank
        
        const fromRank = getRank(fromRow);
        const toRank = getRank(toRow);
        
        const from = `${files[fromCol]}${fromRank}`;
        const to = `${files[toCol]}${toRank}`;
        return `${from}${to}`;
    }

    getBoardState() {
        return this.board.map(row => row.map(piece => piece ? { ...piece } : null));
    }

    getCurrentPlayer() {
        return this.currentPlayer;
    }

    getGameStatus() {
        return this.gameStatus;
    }

    setGameStatus(status) {
        this.gameStatus = status;
    }

    getMoveHistory() {
        return [...this.moveHistory];
    }

    getLastMove() {
        return this.moveHistory[this.moveHistory.length - 1];
    }

    // Add a mistake for the specified player
    addMistake(color) {
        if (color in this.mistakes) {
            this.mistakes[color]++;
        }
    }

    // Get mistake count for a player
    getMistakes(color) {
        return this.mistakes[color] || 0;
    }

    // Get all mistake counts
    getAllMistakes() {
        return { ...this.mistakes };
    }

    isKingInCheck(color) {
        // Find the king's position
        let kingRow, kingCol;
        for (let row = 0; row < 8; row++) {
            for (let col = 0; col < 8; col++) {
                const piece = this.board[row][col];
                if (piece && piece.type === 'king' && piece.color === color) {
                    kingRow = row;
                    kingCol = col;
                    break;
                }
            }
            if (kingRow !== undefined) break;
        }

        // Check if any opponent's piece can capture the king
        const opponentColor = color === 'white' ? 'black' : 'white';
        for (let row = 0; row < 8; row++) {
            for (let col = 0; col < 8; col++) {
                const piece = this.board[row][col];
                if (piece && piece.color === opponentColor) {
                    if (this.isValidMove(row, col, kingRow, kingCol)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    isCheckmate(color) {
        if (!this.isKingInCheck(color)) return false;

        // Try every possible move for each piece
        for (let fromRow = 0; fromRow < 8; fromRow++) {
            for (let fromCol = 0; fromCol < 8; fromCol++) {
                const piece = this.board[fromRow][fromCol];
                if (piece && piece.color === color) {
                    for (let toRow = 0; toRow < 8; toRow++) {
                        for (let toCol = 0; toCol < 8; toCol++) {
                            if (this.isValidMove(fromRow, fromCol, toRow, toCol)) {
                                // Try the move
                                const tempBoard = this.board.map(row => [...row]);
                                this.board[toRow][toCol] = { ...piece, position: [toRow, toCol] };
                                this.board[fromRow][fromCol] = null;

                                const stillInCheck = this.isKingInCheck(color);

                                // Restore the board
                                this.board = tempBoard;

                                if (!stillInCheck) {
                                    return false; // Found a legal move that prevents checkmate
                                }
                            }
                        }
                    }
                }
            }
        }
        return true; // No legal moves found to prevent checkmate
    }

    getFEN() {
        let fen = '';
        
        // Board position (from rank 8 to 1, i.e. top to bottom)
        for (let row = 0; row < 8; row++) {
            let emptySquares = 0;
            for (let col = 0; col < 8; col++) {
                const piece = this.board[row][col];
                if (piece) {
                    if (emptySquares > 0) {
                        fen += emptySquares;
                        emptySquares = 0;
                    }
                    const pieceSymbol = this.getPieceSymbol(piece);
                    // White pieces are uppercase, black pieces are lowercase in FEN
                    fen += piece.color === 'white' ? pieceSymbol.toUpperCase() : pieceSymbol.toLowerCase();
                } else {
                    emptySquares++;
                }
            }
            if (emptySquares > 0) {
                fen += emptySquares;
            }
            if (row < 7) { // Add slash between rows, but not after the last row
                fen += '/';
            }
        }

        // Active color (w for white, b for black)
        fen += ` ${this.currentPlayer === 'white' ? 'w' : 'b'}`;

        // Add castling availability
        let castling = '';
        if (this.castlingRights.white.kingside) castling += 'K';
        if (this.castlingRights.white.queenside) castling += 'Q';
        if (this.castlingRights.black.kingside) castling += 'k';
        if (this.castlingRights.black.queenside) castling += 'q';
        fen += ` ${castling || '-'}`;

        // Add en passant target square
        fen += ` ${this.enPassantTarget}`;

        // Add halfmove clock and fullmove number
        fen += ` ${this.halfMoveClock} ${this.fullMoveNumber}`;

        return fen;
    }

    getPieceSymbol(piece) {
        let symbol = '';
        switch (piece.type) {
            case 'pawn': symbol = 'P'; break;
            case 'knight': symbol = 'N'; break;
            case 'bishop': symbol = 'B'; break;
            case 'rook': symbol = 'R'; break;
            case 'queen': symbol = 'Q'; break;
            case 'king': symbol = 'K'; break;
            default: return '';
        }
        // Convert to lowercase for black pieces
        return piece.color === 'black' ? symbol.toLowerCase() : symbol;
    }
}

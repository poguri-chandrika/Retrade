import express from 'express';
import http from 'http';
import cors from 'cors';
import { Server as SocketIOServer } from 'socket.io';
import crypto from 'node:crypto';

// Keep this in sync with PHP WS_SHARED_SECRET in config.php
const WS_SHARED_SECRET = process.env.WS_SHARED_SECRET || 'change_this_ws_secret';
const PORT = process.env.PORT || 3001;

function verifyToken(token) {
  try {
    // token format: base64(payload).hmac
    const [b64, sig] = String(token || '').split('.');
    if (!b64 || !sig) return null;

    // Recompute HMAC
    const expected = crypto.createHmac('sha256', WS_SHARED_SECRET).update(b64).digest('hex');
    if (expected !== sig) return null;

    const json = Buffer.from(b64.replace(/-/g, '+').replace(/_/g, '/'), 'base64').toString('utf8');
    const payload = JSON.parse(json);
    if (!payload || typeof payload !== 'object') return null;

    // Check exp
    const now = Math.floor(Date.now() / 1000);
    if (payload.exp && now > payload.exp) return null;

    return payload; // { sub, name, role, rooms, ... }
  } catch (e) {
    return null;
  }
}

const app = express();
app.use(cors({
  origin: [
    'http://localhost',
    'http://localhost:80',
    'http://127.0.0.1',
    'http://127.0.0.1:80',
    'http://localhost:8080'
  ],
  credentials: true
}));

const server = http.createServer(app);
const io = new SocketIOServer(server, {
  cors: {
    origin: [
      'http://localhost',
      'http://localhost:80',
      'http://127.0.0.1',
      'http://127.0.0.1:80',
      'http://localhost:8080'
    ],
    methods: ['GET', 'POST'],
    credentials: true
  }
});

// Very basic per-socket rate limit
function createRateLimiter(limitPerSec = 5) {
  let tokens = limitPerSec;
  let last = Date.now();
  return function allow() {
    const now = Date.now();
    const elapsed = (now - last) / 1000;
    last = now;
    tokens = Math.min(limitPerSec, tokens + elapsed * limitPerSec);
    if (tokens < 1) return false;
    tokens -= 1;
    return true;
  }
}

io.use(async (socket, next) => {
  try {
    const { token } = socket.handshake.auth || {};
    const payload = await verifyToken(token);
    if (!payload) return next(new Error('Unauthorized'));
    socket.user = {
      id: payload.sub,
      name: payload.name,
      role: payload.role,
      rooms: Array.isArray(payload.rooms) ? payload.rooms : [],
      productId: payload.product_id,
      buyerId: payload.buyer_id,
      sellerId: payload.seller_id,
    };
    socket.allow = createRateLimiter(8); // 8 msgs/sec burst
    return next();
  } catch (e) {
    return next(new Error('Unauthorized'));
  }
});

io.on('connection', (socket) => {
  // Join requests must be within allowed rooms
  socket.on('join', ({ room }) => {
    if (!room || !socket.user || !socket.user.rooms.includes(room)) return;
    socket.join(room);
    socket.emit('joined', { room });
    // Notify others presence (optional)
    socket.to(room).emit('typing', { userId: socket.user.id, typing: false, name: socket.user.name });
  });

  socket.on('message', ({ room, text }) => {
    if (!socket.allow || !socket.allow()) return;
    if (typeof text !== 'string' || text.length === 0 || text.length > 1000) return;
    if (!room || !socket.user || !socket.user.rooms.includes(room)) return;

    const ts = Date.now();
    const payload = { userId: socket.user.id, name: socket.user.name, text, ts };
    io.to(room).emit('message', payload);
  });

  socket.on('typing', ({ room, typing }) => {
    if (!room || !socket.user || !socket.user.rooms.includes(room)) return;
    socket.to(room).emit('typing', { userId: socket.user.id, typing: !!typing, name: socket.user.name });
  });
});

server.listen(PORT, () => {
  console.log(`[ws] Socket.IO server running on http://localhost:${PORT}`);
});

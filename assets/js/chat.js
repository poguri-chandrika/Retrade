(function(){
  function escapeHtml(str){
    if (typeof str !== 'string') return '';
    return str
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function formatTime(ts){
    try {
      const d = new Date(ts);
      return d.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
    } catch(e){ return '' }
  }

  const chatModalEl = document.getElementById('chatModal');
  if (!chatModalEl) return;

  let socket = null;
  let joinedRoom = null;
  let typingTimeout = null;
  let lastTypedAt = 0;

  const chatMessages = document.getElementById('chatMessages');
  const chatInput = document.getElementById('chatInput');
  const sendBtn = document.getElementById('sendMsgBtn');
  const presenceDot = document.getElementById('chatPresence');
  const typingIndicator = document.getElementById('typingIndicator');
  const presenceText = document.getElementById('chatPresenceText');
  const productTitleEl = document.getElementById('chatProductTitle');
  const attachBtn = document.getElementById('attachBtn');
  const attachInput = document.getElementById('chatAttachment');

  const bsModal = new bootstrap.Modal(chatModalEl);

  function scrollToBottom(){
    chatMessages.scrollTop = chatMessages.scrollHeight;
  }

  function appendMessage({ me, name, text, ts, tempId }){
    const bubble = document.createElement('div');
    bubble.className = 'chat-message ' + (me ? 'me' : 'other');
    if (tempId) bubble.dataset.tempId = String(tempId);
    bubble.innerHTML = `<div>${escapeHtml(text)}</div><span class="meta">${me ? 'You' : escapeHtml(name)} • ${formatTime(ts)}${tempId ? ' • sending…' : ''}</span>`;
    chatMessages.appendChild(bubble);
    scrollToBottom();
    return bubble;
  }

  function setPresence(online){
    if (!presenceDot) return;
    presenceDot.classList.toggle('online', !!online);
    if (presenceText) presenceText.textContent = online ? 'Online' : 'Offline';
  }

  function setTyping(show){
    if (!typingIndicator) return;
    typingIndicator.style.display = show ? 'block' : 'none';
  }

  async function fetchToken({ productId, buyerId, sellerId, role }){
    const form = new FormData();
    form.append('product_id', productId);
    if (role === 'seller') {
      form.append('buyer_id', buyerId);
      const res = await fetch('api/ws_token_seller.php', { method: 'POST', body: form, credentials: 'same-origin' });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Failed to get chat token');
      return data; // { token, ws_url, room, negotiation_id, user }
    }
    const res = await fetch('api/ws_token.php', { method: 'POST', body: form, credentials: 'same-origin' });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Failed to get chat token');
    return data; // { token, ws_url, room, negotiation_id, user }
  }

  function bindSocketEvents(user){
    socket.on('connect', ()=> setPresence(true));
    socket.on('disconnect', ()=> setPresence(false));

    socket.on('joined', (payload)=>{
      // payload: { room }
      joinedRoom = payload.room;
    });

  // Attachment handling
  if (attachBtn && attachInput) {
    attachBtn.addEventListener('click', function(){ attachInput.click(); });
    attachInput.addEventListener('change', async function(){
      try {
        const file = attachInput.files && attachInput.files[0];
        if (!file) return;
        if (!window.__negotiationId || !window.__currentUser) return;
        // show pending bubble
        const caption = file.name;
        const pending = appendMessage({ me: true, name: 'You', text: '[Uploading] ' + caption, ts: Date.now(), tempId: 'att' + Date.now() });
        const form = new FormData();
        form.append('negotiation_id', String(window.__negotiationId));
        form.append('sender_id', String(window.__currentUser.id));
        form.append('message', '');
        form.append('attachment', file);
        const res = await fetch('api/chat_message_save.php', { method: 'POST', body: form, credentials: 'same-origin' });
        const data = await res.json().catch(()=>({}));
        if (!res.ok) throw new Error(data.error || 'Upload failed');
        // remove pending (will be replaced by WS echo), and refresh header
        if (pending) pending.remove();
        window.refreshMessages && window.refreshMessages();
      } catch(err) {
        alert(err.message || 'Attachment error');
      } finally {
        attachInput.value = '';
      }
    });
  }

    socket.on('message', (msg)=>{
      // msg: { userId, name, text, ts }
      const isMe = (msg.userId === user.id);
      // If there is a pending bubble that matches my text, remove it
      if (isMe) {
        const pending = chatMessages.querySelector('.chat-message.me[data-temp-id]');
        if (pending) pending.remove();
      }
      appendMessage({ me: isMe, name: msg.name, text: msg.text, ts: msg.ts });
      // Update header badges in real-time
      window.refreshMessages && window.refreshMessages();
    });

    socket.on('typing', ({ userId, typing, name })=>{
      // Only show typing if it's the other user
      if (userId !== user.id) setTyping(!!typing);
    });
  }

  async function sendMessage(){
    if (!socket || !socket.connected || !joinedRoom) return;
    const text = chatInput.value.trim();
    if (!text) return;
    if (text.length > 1000) {
      alert('Message too long (max 1000 characters).');
      return;
    }
    // Add a temporary pending bubble
    const tempId = Date.now().toString(36) + Math.random().toString(36).slice(2);
    const pendingBubble = appendMessage({ me: true, name: 'You', text, ts: Date.now(), tempId });
    socket.emit('message', { room: joinedRoom, text });
    // Save message
    try {
      if (window.__negotiationId && window.__currentUser) {
        const form = new FormData();
        form.append('negotiation_id', String(window.__negotiationId));
        form.append('sender_id', String(window.__currentUser.id));
        form.append('message', text);
        const res = await fetch('api/chat_message_save.php', { method: 'POST', body: form, credentials: 'same-origin' });
        // If success, header can refresh sooner
        if (res.ok) { window.refreshMessages && window.refreshMessages(); }
      }
    } catch(e){}
    chatInput.value = '';
    // Fallback: if no confirmation arrives, mark failed after 6s
    setTimeout(()=>{
      if (pendingBubble && document.body.contains(pendingBubble)) {
        const meta = pendingBubble.querySelector('.meta');
        if (meta && meta.textContent && meta.textContent.includes('sending…')) {
          meta.textContent = meta.textContent.replace('sending…', 'failed');
          pendingBubble.classList.add('opacity-75');
        }
      }
    }, 6000);
  }

  function notifyTyping(){
    if (!socket || !socket.connected || !joinedRoom) return;
    const now = Date.now();
    if (now - lastTypedAt > 300) {
      socket.emit('typing', { room: joinedRoom, typing: true });
      lastTypedAt = now;
      setTimeout(()=> socket.emit('typing', { room: joinedRoom, typing: false }), 3000);
    }
  }

  async function openChatFromButton(btn){
    try {
      const productId = btn.getAttribute('data-product-id');
      const buyerId = btn.getAttribute('data-buyer-id');
      const sellerId = btn.getAttribute('data-seller-id');
      const role = btn.getAttribute('data-role') || 'buyer';
      const pTitle = btn.getAttribute('data-product-title') || '';
      console.debug('Chat: openChatFromButton click', { role, productId, buyerId, sellerId });
      // Update header subtitle if present
      if (productTitleEl) productTitleEl.textContent = pTitle ? ('for: ' + pTitle) : '';
      bsModal.show();
      setPresence(false);
      setTyping(false);
      chatMessages.innerHTML = '';
      console.debug('Chat: requesting token', { role });
      const { token, ws_url, room, negotiation_id, user } = await fetchToken({ productId, buyerId, sellerId, role });
      console.debug('Chat: token ok', { ws_url, room, negotiation_id, user });
      window.__negotiationId = negotiation_id;
      window.__currentUser = user;
      if (socket) {
        try { socket.disconnect(); } catch(e){}
        socket = null;
      }

      socket = io(ws_url, {
        path: '/socket.io',
        auth: { token },
        withCredentials: true,
        // transports left default to allow polling/websocket negotiation
      });

      bindSocketEvents(user);
      socket.on('connect', ()=> console.debug('Chat: socket connected'));

      // Temporary: surface connection errors to help diagnose issues like "Transport unknown"
      socket.on('connect_error', (err)=>{
        console.error('Chat connect_error:', err?.message || err);
      });

      // Join the negotiated room once connected so the server will accept our messages
      socket.once('connect', ()=>{
        try {
          socket.emit('join', { room });
          console.debug('Chat: join emitted', { room });
        } catch(e){}
      });

      // autofocus input
      setTimeout(()=> chatInput?.focus(), 300);

      // Load history
      try {
        if (window.__negotiationId) {
          const params = new URLSearchParams({ negotiation_id: String(window.__negotiationId), limit: '100' });
          const res = await fetch('api/chat_history.php?' + params.toString(), { credentials: 'same-origin' });
          const data = await res.json();
          if (res.ok && Array.isArray(data.messages)) {
            data.messages.forEach(m => {
              appendMessage({ me: (m.sender_id === user.id), name: m.sender_name || 'User', text: m.message, ts: m.created_at });
            });
            console.debug('Chat: history loaded', { count: data.messages.length });
            // Mark as read for this negotiation
            try {
              const form = new FormData();
              form.append('negotiation_id', String(window.__negotiationId));
              await fetch('api/messages_mark_read.php', { method: 'POST', body: form, credentials: 'same-origin' });
              window.refreshMessages && window.refreshMessages();
            } catch(_){ }
          }
        }
      } catch(e){}

    } catch (e) {
      alert(e.message || 'Unable to start chat');
      console.error('Chat: openChatFromButton failed', e);
    }
  }
  // Event delegation: support multiple buttons
  document.addEventListener('click', function(e){
    const target = e.target.closest('.openChatBtn, #openChatBtn');
    if (target) {
      e.preventDefault();
      openChatFromButton(target);
    }
  });

  if (sendBtn) sendBtn.addEventListener('click', sendMessage);
  chatInput.addEventListener('keydown', function(e){
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    } else {
      notifyTyping();
    }
  });
})();

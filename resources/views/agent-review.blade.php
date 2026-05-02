<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agent Review - PLOS</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #1a1a2e; color: #e0e0e0; padding: 16px; min-height: 100vh; }
        .card { background: #16213e; border-radius: 12px; padding: 20px; margin-bottom: 16px; border: 1px solid #0f3460; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .badge-pending { background: #e67e22; color: #fff; }
        .badge-approved { background: #27ae60; color: #fff; }
        .badge-rejected { background: #e74c3c; color: #fff; }
        .badge-expired { background: #7f8c8d; color: #fff; }
        .badge-priority-2 { background: #e74c3c; }
        .badge-priority-1 { background: #e67e22; }
        h1 { font-size: 18px; color: #fff; margin-bottom: 4px; }
        h2 { font-size: 15px; color: #a0a0a0; font-weight: 400; }
        .meta { font-size: 13px; color: #888; margin-bottom: 12px; }
        .summary { font-size: 14px; line-height: 1.6; margin-bottom: 16px; white-space: pre-wrap; }
        .details { background: #0f3460; border-radius: 8px; padding: 12px; font-size: 13px; line-height: 1.5; margin-bottom: 16px; overflow-x: auto; white-space: pre-wrap; word-break: break-word; max-height: 300px; overflow-y: auto; }
        .details-toggle { color: #3498db; font-size: 13px; cursor: pointer; margin-bottom: 12px; }
        .actions { display: flex; gap: 10px; margin-bottom: 12px; }
        .btn { flex: 1; padding: 14px 16px; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: opacity 0.2s; }
        .btn-sm { padding: 10px 14px; font-size: 14px; }
        .btn:active { opacity: 0.7; }
        .btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .btn-approve { background: #27ae60; color: #fff; }
        .btn-reject { background: #e74c3c; color: #fff; }
        .btn-send { background: #3498db; color: #fff; }
        .btn-secondary { background: #2c3e50; color: #e0e0e0; border: 1px solid #0f3460; }
        textarea, input[type="text"] { width: 100%; padding: 12px; border: 1px solid #0f3460; border-radius: 8px; background: #0f3460; color: #e0e0e0; font-size: 14px; font-family: inherit; }
        textarea { resize: vertical; min-height: 80px; margin-bottom: 12px; }
        textarea::placeholder, input::placeholder { color: #666; }
        .result { padding: 16px; border-radius: 8px; font-size: 15px; font-weight: 600; text-align: center; margin-top: 12px; }
        .result-success { background: #27ae60; color: #fff; }
        .result-error { background: #e74c3c; color: #fff; }
        .confidence-bar { height: 6px; background: #0f3460; border-radius: 3px; margin: 8px 0; }
        .confidence-fill { height: 100%; border-radius: 3px; }
        .chat-messages { max-height: 400px; overflow-y: auto; margin-bottom: 12px; }
        .chat-msg { padding: 10px 14px; border-radius: 10px; margin-bottom: 8px; max-width: 90%; font-size: 14px; line-height: 1.5; white-space: pre-wrap; }
        .chat-human { background: #0f3460; margin-left: auto; text-align: right; border-bottom-right-radius: 4px; }
        .chat-ai { background: #1e3a5f; border-bottom-left-radius: 4px; }
        .chat-meta { font-size: 11px; color: #666; margin-top: 4px; }
        .chat-input { display: flex; gap: 8px; }
        .chat-input input { flex: 1; }
        .chat-input .btn { flex: none; width: auto; padding: 12px 20px; }
        .spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid #666; border-top-color: #fff; border-radius: 50%; animation: spin 0.8s linear infinite; vertical-align: middle; margin-right: 6px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .logo { font-size: 12px; color: #555; text-align: center; margin-top: 20px; }
        .section-label { font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }
    </style>
</head>
<body>
    {{-- Review Item Card --}}
    <div class="card">
        <div class="header">
            <div>
                <h1>{{ $item->title }}</h1>
                <h2>{{ $item->agent_id }} &middot; {{ $item->review_type }}</h2>
            </div>
            <span class="badge badge-{{ $item->status }}">{{ strtoupper($item->status) }}</span>
        </div>

        <div class="meta">
            @if($item->priority >= 2)<span class="badge badge-priority-2">URGENT</span> @elseif($item->priority >= 1)<span class="badge badge-priority-1">HIGH</span> @endif
            @if($item->confidence !== null)
                Confidence: {{ round($item->confidence * 100) }}%
                <div class="confidence-bar">
                    <div class="confidence-fill" style="width: {{ round($item->confidence * 100) }}%; background: {{ $item->confidence >= 0.8 ? '#27ae60' : ($item->confidence >= 0.5 ? '#e67e22' : '#e74c3c') }};"></div>
                </div>
            @endif
            Submitted: {{ \Carbon\Carbon::parse($item->created_at)->diffForHumans() }}
            @if($item->expires_at) &middot; Expires: {{ \Carbon\Carbon::parse($item->expires_at)->diffForHumans() }} @endif
        </div>

        <div class="summary">{{ $item->summary }}</div>

        @php $details = json_decode($item->details, true); @endphp
        @if($details)
            <div class="details-toggle" onclick="document.getElementById('details-block').style.display = document.getElementById('details-block').style.display === 'none' ? 'block' : 'none'">Show/hide details</div>
            <div class="details" id="details-block" style="display:none;">{{ json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</div>
        @endif
    </div>

    {{-- Action Card (pending items only) --}}
    @if($item->status === 'pending')
        <div class="card" id="action-card">
            <div class="section-label">Decision</div>
            <textarea id="notes" placeholder="Optional: comments, instructions, or feedback for the agent..."></textarea>
            <div class="actions">
                <button class="btn btn-approve" onclick="resolve(true)">Approve</button>
                <button class="btn btn-reject" onclick="resolve(false)">Reject</button>
            </div>
        </div>
    @else
        <div class="card">
            <div class="result {{ $item->status === 'approved' ? 'result-success' : 'result-error' }}">
                {{ strtoupper($item->status) }}
                @if($item->reviewed_at) &middot; {{ \Carbon\Carbon::parse($item->reviewed_at)->diffForHumans() }} @endif
            </div>
            @if($item->reviewer_notes)
                <div class="summary" style="margin-top: 12px;">{{ $item->reviewer_notes }}</div>
            @endif
        </div>
    @endif

    <div id="result-msg" style="display:none;"></div>

    {{-- Chat with Agent --}}
    <div class="card" id="chat-card">
        <div class="section-label">Chat with {{ $item->agent_id }}</div>
        <div class="chat-messages" id="chat-messages"></div>
        <div class="chat-input">
            <input type="text" id="chat-input" placeholder="Ask a question or send a command..."
                   onkeydown="if(event.key==='Enter')sendChat()">
            <button class="btn btn-send btn-sm" onclick="sendChat()" id="chat-send-btn">Send</button>
        </div>
    </div>

    <div class="logo">PLOS &middot; Personal Life OS</div>

    <script>
        const token = '{{ $item->token }}';
        const agentId = '{{ $item->agent_id }}';

        // ── Review Actions ──
        async function resolve(approved) {
            const notes = document.getElementById('notes').value;
            const btns = document.querySelectorAll('#action-card .btn');
            btns.forEach(b => b.disabled = true);

            try {
                const resp = await fetch(`/api/agent/review/${token}/resolve`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ approved, notes })
                });
                const data = await resp.json();

                const card = document.getElementById('action-card');
                const msg = document.getElementById('result-msg');
                msg.style.display = 'block';
                msg.className = 'result ' + (data.success ? 'result-success' : 'result-error');
                msg.textContent = data.success
                    ? (approved ? 'APPROVED' : 'REJECTED')
                    : ('Error: ' + (data.error || 'Unknown'));

                if (data.success) card.style.display = 'none';
            } catch (e) {
                showError('Network error: ' + e.message);
                btns.forEach(b => b.disabled = false);
            }
        }

        // ── Chat ──
        function addMessage(text, isHuman, meta = '') {
            const el = document.createElement('div');
            el.className = 'chat-msg ' + (isHuman ? 'chat-human' : 'chat-ai');
            el.textContent = text;
            if (meta) {
                const m = document.createElement('div');
                m.className = 'chat-meta';
                m.textContent = meta;
                el.appendChild(m);
            }
            document.getElementById('chat-messages').appendChild(el);
            el.scrollIntoView({ behavior: 'smooth' });
        }

        function setLoading(on) {
            const btn = document.getElementById('chat-send-btn');
            const input = document.getElementById('chat-input');
            btn.disabled = on;
            input.disabled = on;
            btn.innerHTML = on ? '<span class="spinner"></span>' : 'Send';
        }

        async function sendChat() {
            const input = document.getElementById('chat-input');
            const text = input.value.trim();
            if (!text) return;

            addMessage(text, true);
            input.value = '';
            setLoading(true);

            try {
                const resp = await fetch(`/api/agent/chat/${token}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ message: text })
                });
                const data = await resp.json();
                setLoading(false);

                if (data.success) {
                    addMessage(data.response, false, data.provider ? `via ${data.provider}` : '');
                } else {
                    addMessage('Error: ' + (data.error || 'Unknown'), false);
                }
            } catch (e) {
                setLoading(false);
                addMessage('Network error: ' + e.message, false);
            }
        }

        function showError(msg) {
            const el = document.getElementById('result-msg');
            el.style.display = 'block';
            el.className = 'result result-error';
            el.textContent = msg;
        }
    </script>
</body>
</html>

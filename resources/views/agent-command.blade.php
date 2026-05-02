<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PLOS Command</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #1a1a2e; color: #e0e0e0; padding: 16px; min-height: 100vh; display: flex; flex-direction: column; }
        .card { background: #16213e; border-radius: 12px; padding: 20px; margin-bottom: 16px; border: 1px solid #0f3460; }
        h1 { font-size: 16px; color: #fff; margin-bottom: 4px; }
        .subtitle { font-size: 13px; color: #888; margin-bottom: 16px; }
        .context { font-size: 13px; color: #a0a0a0; margin-bottom: 12px; padding: 10px; background: #0f3460; border-radius: 8px; }
        input[type="text"] { width: 100%; padding: 14px; border: 1px solid #0f3460; border-radius: 8px; background: #0f3460; color: #e0e0e0; font-size: 16px; font-family: inherit; margin-bottom: 12px; }
        input::placeholder { color: #555; }
        .btn { width: 100%; padding: 16px; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; }
        .btn:active { opacity: 0.7; }
        .btn:disabled { opacity: 0.4; }
        .btn-send { background: #3498db; color: #fff; margin-bottom: 8px; }
        .quick-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
        .quick-btn { padding: 8px 14px; border: 1px solid #0f3460; border-radius: 20px; background: #0f3460; color: #e0e0e0; font-size: 13px; cursor: pointer; }
        .quick-btn:active { background: #3498db; }
        .response { padding: 16px; border-radius: 8px; font-size: 14px; line-height: 1.6; white-space: pre-wrap; margin-top: 12px; }
        .response-ok { background: #1e3a5f; border: 1px solid #3498db; }
        .response-err { background: #3d1a1a; border: 1px solid #e74c3c; }
        .spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid #666; border-top-color: #fff; border-radius: 50%; animation: spin 0.8s linear infinite; vertical-align: middle; margin-right: 6px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .logo { font-size: 12px; color: #444; text-align: center; margin-top: auto; padding-top: 20px; }
        .history { margin-top: 12px; }
        .history-item { padding: 8px 0; border-bottom: 1px solid #0f3460; font-size: 13px; }
        .history-cmd { color: #3498db; }
        .history-resp { color: #888; }
    </style>
</head>
<body>
    <div class="card">
        <h1>PLOS Command</h1>
        <div class="subtitle">Send a command or message to the framework</div>

        @if(isset($context))
            <div class="context">Re: {{ $context }}</div>
        @endif

        <div class="quick-actions">
            <span class="quick-btn" onclick="quickCmd('status')">Status</span>
            <span class="quick-btn" onclick="quickCmd('pipeline status')">Pipelines</span>
            <span class="quick-btn" onclick="quickCmd('pause all')">Pause All</span>
            <span class="quick-btn" onclick="quickCmd('resume all')">Resume All</span>
        </div>

        <input type="text" id="cmd-input" placeholder="Type a command or message..."
               onkeydown="if(event.key==='Enter')sendCommand()" autofocus>
        <button class="btn btn-send" onclick="sendCommand()" id="send-btn">Send</button>
    </div>

    <div id="response-area"></div>

    <div class="card" id="history-card" style="display:none;">
        <div style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px;">History</div>
        <div id="history"></div>
    </div>

    <div class="logo">PLOS &middot; Personal Life OS</div>

    <script>
        const agentId = '{{ $agentId ?? "system" }}';
        const reviewToken = '{{ $reviewToken ?? "" }}';

        function quickCmd(cmd) {
            document.getElementById('cmd-input').value = cmd;
            sendCommand();
        }

        async function sendCommand() {
            const input = document.getElementById('cmd-input');
            const text = input.value.trim();
            if (!text) return;

            const btn = document.getElementById('send-btn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Sending...';
            input.disabled = true;

            try {
                const resp = await fetch('/api/agent/command', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        command: text,
                        agent_id: agentId,
                        review_token: reviewToken || undefined,
                        notify: true
                    })
                });
                const data = await resp.json();

                const area = document.getElementById('response-area');
                const div = document.createElement('div');
                div.className = 'response ' + (data.success ? 'response-ok' : 'response-err');
                div.textContent = data.success ? data.response : ('Error: ' + (data.error || 'Unknown'));
                area.innerHTML = '';
                area.appendChild(div);

                // Add to history
                addHistory(text, data.success ? data.response : data.error);

                input.value = '';
            } catch (e) {
                const area = document.getElementById('response-area');
                area.innerHTML = '<div class="response response-err">Network error: ' + e.message + '</div>';
            } finally {
                btn.disabled = false;
                btn.innerHTML = 'Send';
                input.disabled = false;
                input.focus();
            }
        }

        function addHistory(cmd, resp) {
            const card = document.getElementById('history-card');
            card.style.display = 'block';
            const el = document.createElement('div');
            el.className = 'history-item';
            el.innerHTML = '<span class="history-cmd">&gt; ' + escapeHtml(cmd) + '</span><br><span class="history-resp">' + escapeHtml((resp || '').substring(0, 200)) + '</span>';
            document.getElementById('history').prepend(el);
        }

        function escapeHtml(s) {
            const d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }
    </script>
</body>
</html>

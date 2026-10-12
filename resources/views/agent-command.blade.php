<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PLOS Command</title>
    <style>
        :root { --ink: #162c39; --muted: #536b77; --line: #d8e2e5; --teal: #096b62; --paper: #fff; --canvas: #f2f5f5; --danger: #9d3434; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--canvas); color: var(--ink); padding: 20px; min-height: 100vh; max-width: 900px; margin: auto; display: flex; flex-direction: column; }
        .card { background: var(--paper); border-radius: 12px; padding: 20px; margin-bottom: 16px; border: 1px solid var(--line); box-shadow: 0 7px 28px #1534440a; }
        h1 { font-size: 22px; color: var(--ink); margin-bottom: 4px; letter-spacing: -.4px; }
        .subtitle { font-size: 13px; color: var(--muted); margin-bottom: 16px; }
        .context { font-size: 13px; color: var(--muted); margin-bottom: 12px; padding: 10px; background: #f1f5f5; border-radius: 8px; }
        input[type="text"] { width: 100%; padding: 14px; border: 1px solid var(--line); border-radius: 8px; background: var(--paper); color: var(--ink); font-size: 16px; font-family: inherit; margin-bottom: 12px; }
        input::placeholder { color: var(--muted); }
        .btn { width: 100%; padding: 16px; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; }
        .btn:active { opacity: 0.7; }
        .btn:disabled { opacity: 0.4; }
        .btn-send { background: var(--teal); color: #fff; margin-bottom: 8px; }
        .quick-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
        .quick-btn { padding: 8px 14px; border: 1px solid var(--line); border-radius: 20px; background: var(--paper); color: var(--ink); font-size: 13px; cursor: pointer; }
        .quick-btn:active { background: #e7f4ef; }
        .response { padding: 16px; border-radius: 8px; font-size: 14px; line-height: 1.6; white-space: pre-wrap; margin-top: 12px; }
        .response-ok { background: #e7f4ef; border: 1px solid #afd2c4; }
        .response-err { background: #fbeaea; color: var(--danger); border: 1px solid #edc7c7; }
        .spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid var(--line); border-top-color: var(--teal); border-radius: 50%; animation: spin 0.8s linear infinite; vertical-align: middle; margin-right: 6px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .logo { font-size: 12px; color: var(--muted); text-align: center; margin-top: auto; padding-top: 20px; }
        .history { margin-top: 12px; }
        .history-item { padding: 8px 0; border-bottom: 1px solid var(--line); font-size: 13px; }
        .history-cmd { color: var(--teal); }
        .history-resp { color: var(--muted); }
        #history-card > div:first-child { color: var(--muted) !important; }
        :focus-visible { outline: 3px solid var(--teal); outline-offset: 3px; }
        @media (max-width: 560px) { body { padding: 14px; } }
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

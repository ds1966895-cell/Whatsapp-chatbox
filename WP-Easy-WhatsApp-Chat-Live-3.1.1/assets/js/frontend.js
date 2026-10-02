
document.addEventListener('DOMContentLoaded', function () {

    const widgets = document.querySelectorAll('.wewc-widget');

    const apiBase = window.wewcChatConfig?.apiBase;

    const storageKey = 'wewc_chat_session_v1';

    widgets.forEach(function (widget) {

        const trigger = widget.querySelector('.wewc-trigger');
        const panel = widget.querySelector('.wewc-panel');
        const closeButton = widget.querySelector('.wewc-close');

        const chatForm = widget.querySelector('.wewc-chat-form');
        const chatInput = widget.querySelector('.wewc-chat-input');
        const sendButton = widget.querySelector('.wewc-chat-send');

        const messagesContainer = widget.querySelector(
            '.wewc-chat-messages'
        );

        const statusElement = widget.querySelector(
            '.wewc-chat-status'
        );

        if (
            !trigger ||
            !panel ||
            !closeButton ||
            !chatForm ||
            !chatInput ||
            !sendButton ||
            !messagesContainer ||
            !statusElement
        ) {
            return;
        }

        let session = null;
        let afterId = 0;
        let polling = false;
        let initializing = null;


        // Update chat status
        function setStatus(message) {
            statusElement.textContent = message;
        }


        // Make authenticated API request
        async function apiRequest(endpoint, payload) {

            if (!apiBase) {
                throw new Error('Chat API is not configured.');
            }

            const response = await fetch(apiBase + endpoint, {

                method: 'POST',

                headers: {
                    'Content-Type': 'application/json'
                },

                credentials: 'same-origin',

                cache: 'no-store',

                body: JSON.stringify(payload)

            });

            const data = await response.json();

            if (!response.ok || !data.success) {

                const error = new Error(
                    data.message || 'Chat request failed.'
                );

                error.status = response.status;

                throw error;
            }

            return data;
        }


        // Load existing session
        function loadSavedSession() {

            try {

                const saved = localStorage.getItem(storageKey);

                if (!saved) {
                    return null;
                }

                const data = JSON.parse(saved);

                if (
                    Number.isSafeInteger(data.conversation_id) &&
                    data.conversation_id > 0 &&
                    typeof data.visitor_token === 'string' &&
                    /^[a-f0-9]{64}$/.test(data.visitor_token)
                ) {
                    return data;
                }

            } catch (error) {
                return null;
            }

            return null;
        }


        // Save session in visitor browser
        function saveSession(data) {

            try {
                localStorage.setItem(
                    storageKey,
                    JSON.stringify(data)
                );
            } catch (error) {
                // Chat still works for the current page.
            }
        }


        // Clear invalid session
        function clearSession() {

            session = null;
            afterId = 0;

            chatInput.disabled = true;
            sendButton.disabled = true;

            messagesContainer.replaceChildren();

            try {
                localStorage.removeItem(storageKey);
            } catch (error) {
                // Ignore unavailable browser storage.
            }
        }


        // Display a message inside the chatbox
        function renderMessage(message) {

            const id = Number(message.id);

            if (!Number.isSafeInteger(id) || id <= afterId) {
                return;
            }

            const bubble = document.createElement('div');

            bubble.classList.add('wewc-chat-bubble');

            if (message.sender === 'visitor') {

                bubble.classList.add('wewc-chat-visitor');

            } else {

                bubble.classList.add('wewc-chat-agent');

            }

            // Safely display message text.
            bubble.textContent = String(message.body || '');

            messagesContainer.appendChild(bubble);

            afterId = id;

            // Scroll to newest message.
            messagesContainer.scrollTop =
                messagesContainer.scrollHeight;
        }


        // Retrieve new messages
        async function pollMessages() {

            if (!session || polling) {
                return false;
            }

            polling = true;

            try {

                const data = await apiRequest('messages', {

                    conversation_id: session.conversation_id,

                    visitor_token: session.visitor_token,

                    after_id: afterId

                });

                data.messages.forEach(renderMessage);

                return true;

            } catch (error) {

                if (error.status === 403) {

                    clearSession();

                    setStatus(
                        'Session expired. Close and reopen the chat.'
                    );

                } else {

                    setStatus(
                        'Unable to load messages. Retrying...'
                    );

                }

                return false;

            } finally {

                polling = false;

            }
        }


        // Create or restore visitor session
        async function ensureSession() {

            if (initializing) {
                return initializing;
            }

            initializing = (async function () {

                setStatus('Connecting to chat...');

                chatInput.disabled = true;
                sendButton.disabled = true;

                // A browser refresh starts a fresh visible chat session.
                // The server-side conversation remains stored for WhatsApp routing.
                try {
                    localStorage.removeItem(storageKey);
                } catch (error) {
                    // Ignore unavailable browser storage.
                }

                if (!session) {
                    session = loadSavedSession();
                }

                // Create new session when needed.
                if (!session) {

                    const data = await apiRequest('session', {});

                    session = {

                        conversation_id: Number(
                            data.conversation_id
                        ),

                        visitor_token: data.visitor_token

                    };

                    saveSession(session);
                }

                // Load existing conversation history.
                const loaded = await pollMessages();

                if (session && loaded) {

                    chatInput.disabled = false;
                    sendButton.disabled = false;

                    setStatus('Chat connected');

                }

            })();

            try {

                await initializing;

            } catch (error) {

                setStatus(
                    'Unable to connect. Close and reopen the chat.'
                );

            } finally {

                initializing = null;

            }
        }


        // Open chat popup
        function openPanel() {

            panel.hidden = false;

            trigger.setAttribute(
                'aria-expanded',
                'true'
            );

            closeButton.focus();

            ensureSession();
        }


        // Close chat popup
        function closePanel(returnFocus = false) {

            panel.hidden = true;

            trigger.setAttribute(
                'aria-expanded',
                'false'
            );

            if (returnFocus) {
                trigger.focus();
            }
        }


        // Floating button click
        trigger.addEventListener('click', function () {

            if (panel.hidden) {

                openPanel();

            } else {

                closePanel(true);

            }

        });


        // Close button
        closeButton.addEventListener('click', function () {

            closePanel(true);

        });


        // Send visitor message
        chatForm.addEventListener('submit', async function (event) {

            event.preventDefault();

            if (!session) {
                return;
            }

            const message = chatInput.value.trim();

            if (!message) {
                return;
            }

            sendButton.disabled = true;

            setStatus('Sending message...');

            try {

                const result = await apiRequest('message', {

                    conversation_id: session.conversation_id,

                    visitor_token: session.visitor_token,

                    message: message

                });

                // The website message is saved even when provider delivery
                // is temporarily unavailable. The background worker retries it.
                chatInput.value = '';

                if (result.delivery_status === 'sent') {
                    setStatus('Message sent');
                } else if (result.delivery_status === 'failed') {
                    setStatus('Message saved. WhatsApp delivery will retry automatically.');
                } else {
                    setStatus('Message saved. Waiting for WhatsApp delivery...');
                }

                // Immediately display the saved message.
                await pollMessages();

            } catch (error) {

                setStatus(
                    error.message || 'Unable to send message.'
                );

            } finally {

                if (session) {
                    sendButton.disabled = false;
                }

            }

        });


        // Automatically check for replies every 5 seconds.
        setInterval(function () {

            if (!panel.hidden && session) {

                pollMessages();

            }

        }, 5000);


        // Escape key closes popup.
        document.addEventListener('keydown', function (event) {

            if (
                event.key === 'Escape' &&
                !panel.hidden
            ) {

                closePanel(true);

            }

        });


        // Click outside closes popup.
        document.addEventListener('click', function (event) {

            if (
                !panel.hidden &&
                !widget.contains(event.target)
            ) {

                closePanel();

            }

        });

    });

});
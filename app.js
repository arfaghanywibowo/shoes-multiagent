/**
 * Shoe Multi-Agent AI - Client Application Logic
 */

// Global callback for Google Identity Services
window.handleCredentialResponse = function(response) {
    const token = response.credential;
    // Decode JWT payload (Base64Url decoding)
    const base64Url = token.split('.')[1];
    const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
    const jsonPayload = decodeURIComponent(atob(base64).split('').map(function(c) {
        return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
    }).join(''));
    const payload = JSON.parse(jsonPayload);
    
    // Update UI elements
    const googleLoginBtn = document.getElementById('google-login-btn');
    const userProfileDisplay = document.getElementById('user-profile-display');
    const userAvatarImg = document.getElementById('user-avatar-img');
    const userNameText = document.getElementById('user-name-text');
    
    if (googleLoginBtn) googleLoginBtn.style.display = 'none';
    if (userProfileDisplay) userProfileDisplay.style.display = 'flex';
    if (userAvatarImg) userAvatarImg.src = payload.picture;
    if (userNameText) userNameText.textContent = payload.name;
    
    // Call existing showToast if available
    if (typeof showToast === 'function') {
        showToast('Berhasil masuk sebagai ' + payload.name);
    }
};

document.addEventListener('DOMContentLoaded', () => {
    // Application State
    const state = {
        products: [],
        chats: [],
        agents: [],
        settings: {
            darkMode: true,
            notifications: false,
            chatVoice: true,
            language: 'Indonesia'
        },
        currentSessionId: 'session_default',
        user: null
    };

    // DOM Elements
    const elements = {
        // Navigation & Layout
        menuItems: document.querySelectorAll('.menu-item'),
        pageSections: document.querySelectorAll('.page-section'),
        currentPageTitle: document.getElementById('current-page-title'),
        darkModeToggle: document.getElementById('dark-mode-toggle'),
        settingsDarkMode: document.getElementById('settings-dark-mode'),
        settingsNotifications: document.getElementById('settings-notifications'),
        settingsChatVoice: document.getElementById('settings-chat-voice'),
        settingsLanguage: document.getElementById('settings-language'),
        settingsGeminiKey: document.getElementById('settings-gemini-key'),
        
        // Auth
        googleLoginBtn: document.getElementById('google-login-btn'),
        userProfileDisplay: document.getElementById('user-profile-display'),
        logoutBtn: document.getElementById('logout-btn'),
        
        // Chat
        chatSessionsList: document.getElementById('chat-sessions-list'),
        chatMessagesContainer: document.getElementById('chat-messages-container'),
        chatForm: document.getElementById('chat-form'),
        chatInputField: document.getElementById('chat-input-field'),
        btnAttachment: document.getElementById('btn-attachment'),
        suggestionChips: document.querySelectorAll('.chip-btn'),
        btnClearChats: document.getElementById('btn-clear-chats'),
        
        // Products
        productsGridContainer: document.getElementById('products-grid-container'),
        
        // Agents
        agentsListContainer: document.getElementById('agents-list-container'),
        
        // Excel Import
        excelDragDropZone: document.getElementById('excel-drag-drop-zone'),
        excelFileInput: document.getElementById('excel-file-input'),
        downloadTemplateLink: document.getElementById('download-template-link'),
        uploadFeedbackDisplay: document.getElementById('upload-feedback-display'),
        
        // Toast
        toastNotification: document.getElementById('toast-notification')
    };

    // Initialize App
    init();

    function init() {
        setupNavigation();
        setupEventListeners();
        loadSettings();
        loadProducts();
        loadAgents();
        loadChats();
        setupExcelImport();
        
        // Auto navigate to new chat page if no history
        // (Just keep Histori active as default in index.html to match screenshot)
    }

    // ==========================================
    // 1. NAVIGATION & SPA ROUTING
    // ==========================================
    function setupNavigation() {
        elements.menuItems.forEach(item => {
            item.addEventListener('click', () => {
                const targetPage = item.getAttribute('data-page');
                
                // Update navigation buttons
                elements.menuItems.forEach(btn => btn.classList.remove('active'));
                item.classList.add('active');
                
                // Update visible section
                elements.pageSections.forEach(section => {
                    section.classList.remove('active');
                    if (section.id === targetPage) {
                        section.classList.add('active');
                    }
                });
                
                // Update title
                const titles = {
                    'chat-page': 'Histori Obrolan',
                    'new-chat-page': 'Mulai Obrolan Baru',
                    'products-page': 'Katalog Produk',
                    'agents-page': 'Informasi Agent',
                    'settings-page': 'Pengaturan'
                };
                elements.currentPageTitle.textContent = titles[targetPage] || 'Shoe Multi-Agent AI';
                
                // Extra setup for specific pages
                if (targetPage === 'chat-page') {
                    scrollToBottom();
                }
            });
        });
    }

    // Navigates to a page programmatically
    function navigateToPage(pageId) {
        const item = document.querySelector(`.menu-item[data-page="${pageId}"]`);
        if (item) {
            item.click();
        }
    }

    // Auth Logout
    if (elements.logoutBtn) {
        elements.logoutBtn.addEventListener('click', () => {
            if (elements.userProfileDisplay) elements.userProfileDisplay.style.display = 'none';
            if (elements.googleLoginBtn) elements.googleLoginBtn.style.display = 'block';
            showToast('Anda telah keluar (Logout).');
        });
    }

    // ==========================================
    // 2. SETTINGS & THEMING
    // ==========================================
    function loadSettings() {
        fetch('/api/settings')
            .then(res => res.json())
            .then(data => {
                state.settings = data;
                applySettings();
            })
            .catch(err => console.error('Error fetching settings:', err));
    }

    function applySettings() {
        // Toggle Dark Mode Class
        if (state.settings.darkMode) {
            document.body.classList.add('dark-mode');
            document.body.classList.remove('light-mode');
        } else {
            document.body.classList.add('light-mode');
            document.body.classList.remove('dark-mode');
        }
        
        // Sync toggles in UI
        if (elements.darkModeToggle) elements.darkModeToggle.checked = state.settings.darkMode;
        if (elements.settingsDarkMode) elements.settingsDarkMode.checked = state.settings.darkMode;
        if (elements.settingsNotifications) elements.settingsNotifications.checked = state.settings.notifications;
        if (elements.settingsChatVoice) elements.settingsChatVoice.checked = state.settings.chatVoice;
        if (elements.settingsLanguage) elements.settingsLanguage.value = state.settings.language;
        if (elements.settingsGeminiKey) elements.settingsGeminiKey.value = state.settings.geminiApiKey || '';
    }

    function updateSetting(key, value) {
        state.settings[key] = value;
        applySettings();
        
        // Sync Sidebar Toggle with Settings Pane Toggle
        if (key === 'darkMode') {
            if (elements.darkModeToggle) elements.darkModeToggle.checked = value;
            if (elements.settingsDarkMode) elements.settingsDarkMode.checked = value;
        }

        fetch('/api/settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ [key]: value })
        })
        .then(res => res.json())
        .then(res => {
            if (res.status === 'success') {
                showToast(`Pengaturan ${key} berhasil diperbarui.`);
            }
        })
        .catch(err => console.error('Error saving setting:', err));
    }

    function setupEventListeners() {
        // Dark Mode Sidebar Toggle
        if (elements.darkModeToggle) {
            elements.darkModeToggle.addEventListener('change', (e) => {
                updateSetting('darkMode', e.target.checked);
            });
        }
        
        // Dark Mode Settings Toggle
        if (elements.settingsDarkMode) {
            elements.settingsDarkMode.addEventListener('change', (e) => {
                updateSetting('darkMode', e.target.checked);
            });
        }

        // Notification Toggle
        if (elements.settingsNotifications) {
            elements.settingsNotifications.addEventListener('change', (e) => {
                updateSetting('notifications', e.target.checked);
            });
        }

        // Chat Voice Toggle
        if (elements.settingsChatVoice) {
            elements.settingsChatVoice.addEventListener('change', (e) => {
                updateSetting('chatVoice', e.target.checked);
            });
        }

        // Language Dropdown
        if (elements.settingsLanguage) {
            elements.settingsLanguage.addEventListener('change', (e) => {
                updateSetting('language', e.target.value);
            });
        }

        // Gemini API Key Input
        if (elements.settingsGeminiKey) {
            elements.settingsGeminiKey.addEventListener('change', (e) => {
                updateSetting('geminiApiKey', e.target.value);
            });
        }

        // Clear Chat History
        if (elements.btnClearChats) {
            elements.btnClearChats.addEventListener('click', () => {
                if (confirm('Apakah Anda yakin ingin menghapus semua riwayat chat?')) {
                    fetch('/api/settings/clear-chats', { method: 'POST' })
                        .then(res => res.json())
                        .then(res => {
                            if (res.status === 'success') {
                                showToast('Semua riwayat chat berhasil dihapus.');
                                state.currentSessionId = 'session_default';
                                loadChats(); // Reload chats
                                navigateToPage('chat-page');
                            }
                        })
                        .catch(err => console.error('Error clearing chats:', err));
                }
            });
        }

        // Send message form
        if (elements.chatForm) {
            elements.chatForm.addEventListener('submit', (e) => {
                e.preventDefault();
                sendMessage();
            });
        }

        // Suggestion chips
        elements.suggestionChips.forEach(chip => {
            chip.addEventListener('click', () => {
                const prompt = chip.getAttribute('data-prompt');
                elements.chatInputField.value = prompt;
                navigateToPage('chat-page');
                sendMessage();
            });
        });

        // Attachment button simulasi
        if (elements.btnAttachment) {
            elements.btnAttachment.addEventListener('click', () => {
                showToast('Fitur unggah berkas chat akan segera hadir (Simulasi).');
            });
        }


        // Download Template Excel Link
        if (elements.downloadTemplateLink) {
            elements.downloadTemplateLink.addEventListener('click', (e) => {
                e.preventDefault();
                downloadExcelTemplate();
            });
        }
    }



    // ==========================================
    // 4. CHAT HISTORY & MESSAGING
    // ==========================================
    function loadChats() {
        fetch('/api/chats')
            .then(res => res.json())
            .then(data => {
                state.chats = Array.isArray(data) ? data : [];
                
                if (state.chats.length === 0) {
                    // No sessions exist (Vercel cold start) — create one
                    return createNewChatSession().then(() => {
                        renderSessionsList();
                        renderCurrentSession();
                    });
                }
                
                // Auto-select first session if current one doesn't exist
                const currentExists = state.chats.some(c => c.id === state.currentSessionId);
                if (!currentExists) {
                    state.currentSessionId = state.chats[0].id;
                }
                
                renderSessionsList();
                renderCurrentSession();
            })
            .catch(err => console.error('Error fetching chats:', err));
    }

    // Creates a new chat session via API and updates state
    function createNewChatSession() {
        return fetch('/api/chats', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title: 'Obrolan Baru' })
        })
        .then(res => res.json())
        .then(newChat => {
            state.chats.push(newChat);
            state.currentSessionId = newChat.id;
        })
        .catch(err => console.error('Error creating chat session:', err));
    }

    function renderSessionsList() {
        elements.chatSessionsList.innerHTML = '';
        if (state.chats.length === 0) return;

        state.chats.forEach(session => {
            const btn = document.createElement('button');
            btn.className = `session-btn ${session.id === state.currentSessionId ? 'active' : ''}`;
            btn.textContent = session.title;
            btn.addEventListener('click', () => {
                state.currentSessionId = session.id;
                renderSessionsList();
                renderCurrentSession();
            });
            elements.chatSessionsList.appendChild(btn);
        });
    }

    function renderCurrentSession() {
        elements.chatMessagesContainer.innerHTML = '';
        const currentSession = state.chats.find(c => c.id === state.currentSessionId);
        if (!currentSession) return;

        currentSession.messages.forEach(msg => {
            appendMessageToUI(msg);
        });
        scrollToBottom();
    }

    function appendMessageToUI(msg) {
        const isUser = msg.sender === 'user';
        const bubble = document.createElement('div');
        bubble.className = `message-bubble ${isUser ? 'user' : 'ai'}`;
        
        // Avatar icon
        let avatarIconHtml = '';
        if (isUser) {
            avatarIconHtml = '<i class="fa-solid fa-user"></i>';
        } else {
            // Find agent icon
            if (msg.agent === 'Grade Analyzer Agent') {
                avatarIconHtml = '<i class="fa-solid fa-lock agent-lock-icon"></i>';
            } else if (msg.agent === 'Size Recommender Agent') {
                avatarIconHtml = '<i class="fa-solid fa-ruler-combined"></i>';
            } else if (msg.agent === 'Style Advisor Agent') {
                avatarIconHtml = '<i class="fa-solid fa-wand-magic-sparkles"></i>';
            } else if (msg.agent === 'Stock Checker Agent') {
                avatarIconHtml = '<i class="fa-solid fa-box-open"></i>';
            } else {
                avatarIconHtml = '<i class="fa-solid fa-robot"></i>'; // Default bot
            }
        }
        
        // Process markdown tables to HTML tables for AI messages
        let textHtml = formatMarkdownText(msg.text);

        // Build HTML
        bubble.innerHTML = `
            <div class="message-avatar" style="${!isUser && msg.agent ? 'background: linear-gradient(135deg, ' + getAgentColor(msg.agent) + ', #d97706);' : ''}">
                ${avatarIconHtml}
            </div>
            <div class="message-content-wrapper">
                <div class="message-bubble-content">
                    ${textHtml}
                </div>
                <div class="message-meta">
                    <span>${msg.timestamp}</span>
                    ${isUser ? '<i class="fa-solid fa-check-double"></i>' : ''}
                </div>
            </div>
        `;

        elements.chatMessagesContainer.appendChild(bubble);
    }

    function getAgentColor(agentName) {
        const agents = {
            'Grade Analyzer Agent': '#10b981',
            'Size Recommender Agent': '#f97316',
            'Style Advisor Agent': '#8b5cf6',
            'Stock Checker Agent': '#d97706'
        };
        return agents[agentName] || '#ea580c';
    }

    // Handles simple Markdown parsing (bold and table structures)
    function formatMarkdownText(text) {
        if (!text) return '';
        
        // Escaping tags first
        let html = text
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;");
            
        // Parse bold **text**
        html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');

        // Parse tables
        const lines = html.split('\n');
        let inTable = false;
        let tableRows = [];
        let outputLines = [];

        for (let i = 0; i < lines.length; i++) {
            const line = lines[i].trim();
            if (line.startsWith('|') && line.endsWith('|')) {
                inTable = true;
                tableRows.push(line);
            } else {
                if (inTable) {
                    outputLines.push(buildHtmlTable(tableRows));
                    tableRows = [];
                    inTable = false;
                }
                outputLines.push(line);
            }
        }
        if (inTable) {
            outputLines.push(buildHtmlTable(tableRows));
        }

        return outputLines.join('<br>');
    }

    function buildHtmlTable(rows) {
        if (rows.length < 2) return '';
        
        let html = '<table>';
        // Parse Header
        const headers = rows[0].split('|').map(s => s.trim()).filter((s, idx, arr) => idx > 0 && idx < arr.length - 1);
        
        // Skip rows[1] which is the alignment separator e.g., | --- | --- |
        
        html += '<thead><tr>';
        headers.forEach(h => {
            html += `<th>${h}</th>`;
        });
        html += '</tr></thead><tbody>';

        // Parse rows starting index 2
        for (let i = 2; i < rows.length; i++) {
            const cells = rows[i].split('|').map(s => s.trim()).filter((s, idx, arr) => idx > 0 && idx < arr.length - 1);
            html += '<tr>';
            cells.forEach(c => {
                html += `<td>${c}</td>`;
            });
            html += '</tr>';
        }
        
        html += '</tbody></table>';
        return html;
    }

    function sendMessage() {
        const text = elements.chatInputField.value.trim();
        if (empty(text)) return;

        elements.chatInputField.value = '';
        
        // Create user message state object
        const timestamp = new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) + ' AM';
        const userMsg = {
            id: 'm_temp_' + Date.now(),
            sender: 'user',
            text: text,
            timestamp: timestamp,
            agent: null
        };

        // Append to UI immediately
        appendMessageToUI(userMsg);
        scrollToBottom();

        // Render Typing Indicator
        const typingBubble = document.createElement('div');
        typingBubble.className = 'message-bubble ai typing-indicator-bubble';
        typingBubble.innerHTML = `
            <div class="message-avatar">
                <i class="fa-solid fa-spinner fa-spin"></i>
            </div>
            <div class="message-content-wrapper">
                <div class="message-bubble-content">
                    <div class="typing-indicator">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                </div>
            </div>
        `;
        elements.chatMessagesContainer.appendChild(typingBubble);
        scrollToBottom();

        // Ensure we have a valid session before sending
        const sessionExists = state.chats.some(c => c.id === state.currentSessionId);
        const doSend = sessionExists 
            ? Promise.resolve() 
            : createNewChatSession();
        
        doSend.then(() => {
        // Post to API
        fetch('/api/chats/message', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                sessionId: state.currentSessionId,
                text: text
            })
        })
        .then(res => res.json())
        .then(data => {
            // Remove typing bubble
            const spinner = document.querySelector('.typing-indicator-bubble');
            if (spinner) spinner.remove();

            if (data.status === 'success') {
                // Update local chat records
                const idx = state.chats.findIndex(c => c.id === state.currentSessionId);
                if (idx !== -1) {
                    state.chats[idx] = data.chat;
                }
                
                // Append AI message
                appendMessageToUI(data.message);
                scrollToBottom();
                renderSessionsList(); // Title might have changed
                
                // Voice Speech Synthesis
                if (state.settings.chatVoice) {
                    speakText(data.message.text);
                }
            } else {
                showToast('Gagal memproses pesan AI.');
            }
        })
        .catch(err => {
            const spinner = document.querySelector('.typing-indicator-bubble');
            if (spinner) spinner.remove();
            console.error('Error sending message:', err);
            showToast('Kesalahan koneksi ke server.');
        });
        }); // end doSend.then
    }

    function speakText(text) {
        if (!('speechSynthesis' in window)) return;
        
        // Strip markdown characters, links, tables before speaking
        let cleanText = text
            .replace(/\|/g, ' ')
            .replace(/-{3,}/g, ' ')
            .replace(/\*\*/g, '')
            .replace(/\*/g, '')
            .substring(0, 150); // limit speech output length

        const utterance = new SpeechSynthesisUtterance(cleanText);
        utterance.lang = state.settings.language === 'English' ? 'en-US' : 'id-ID';
        
        // Speak
        window.speechSynthesis.cancel(); // Stop active speaking first
        window.speechSynthesis.speak(utterance);
    }

    function scrollToBottom() {
        elements.chatMessagesContainer.scrollTop = elements.chatMessagesContainer.scrollHeight;
    }

    // ==========================================
    // 5. PRODUCTS CATALOG DISPLAY
    // ==========================================
    function loadProducts() {
        fetch('/api/products')
            .then(res => res.json())
            .then(data => {
                state.products = data;
                renderProducts();
            })
            .catch(err => console.error('Error loading products:', err));
    }

    function renderProducts() {
        elements.productsGridContainer.innerHTML = '';
        if (state.products.length === 0) {
            elements.productsGridContainer.innerHTML = '<div class="no-products-msg"><i class="fa-solid fa-shoe-prints"></i> Katalog produk kosong. Silakan upload template Excel di Pengaturan.</div>';
            return;
        }

        state.products.forEach(p => {
            const card = document.createElement('div');
            card.className = 'product-card';
            
            // Format Rupiah price
            const priceFormatted = 'Rp ' + p.price.toLocaleString('id-ID');
            const gradeClass = p.grade.toLowerCase().replace(' ', '-');

            // Map brand or name to photo from assets/
            const b = (p.brand || '').toLowerCase();
            const n = (p.name || '').toLowerCase();
            let imageTag = '';

            // Check specific shoe models first
            if (n.includes('knu skool')) imageTag = '<img src="assets/knu skool.jpg" alt="Knu Skool">';
            else if (n.includes('sling bag')) imageTag = '<img src="assets/sling bag.jpg" alt="Sling Bag">';
            else if (n.includes('converse') || n.includes('chuck') || b.includes('converse')) imageTag = '<img src="assets/converse chuck 70.jpg" alt="Converse">';
            else if (b.includes('asics')) imageTag = '<img src="assets/Asics Gel-Kayano 29.jpg" alt="Asics">';
            else if (b.includes('fila')) imageTag = '<img src="assets/fila.jpg" alt="Fila">';
            else if (b.includes('onitsuka')) imageTag = '<img src="assets/onitsuka.jpg" alt="Onitsuka Tiger">';
            else if (b.includes('reebok')) imageTag = '<img src="assets/reebok.jpg" alt="Reebok">';
            else if (b.includes('skechers') || b.includes('skecehers')) imageTag = '<img src="assets/skecehers.jpg" alt="Skechers">';
            
            // General brands fallback
            else if (b.includes('nike') || b.includes('jordan')) imageTag = '<img src="assets/air-jordan.jpg" alt="Nike">';
            else if (b.includes('adidas') || b.includes('yeezy')) imageTag = '<img src="assets/adidas.jpg" alt="Adidas">';
            else if (b.includes('puma')) imageTag = '<img src="assets/puma.jpg" alt="Puma">';
            else if (b.includes('vans')) imageTag = '<img src="assets/vans.jpg" alt="Vans">';
            else if (b.includes('nb') || b.includes('new balance')) imageTag = '<img src="assets/newBalance.jpg" alt="NB">';
            else imageTag = p.emoji || '👟'; // Default to emoji if no photo

            card.innerHTML = `
                <div class="product-image-container" style="background: ${p.gradient || 'linear-gradient(135deg, #3b82f6, #8b5cf6)'};">
                    ${imageTag}
                </div>
                <div class="product-info">
                    <span class="product-name">${p.name}</span>
                    <span class="product-brand">${p.brand}</span>
                    <div class="product-meta-row">
                        <span class="product-price">${priceFormatted}</span>
                        <span class="grade-badge ${gradeClass}">${p.grade}</span>
                    </div>
                </div>
            `;
            elements.productsGridContainer.appendChild(card);
        });
    }

    // ==========================================
    // 6. AGENTS OVERVIEW DISPLAY
    // ==========================================
    function loadAgents() {
        fetch('/api/agents')
            .then(res => res.json())
            .then(data => {
                state.agents = data;
                renderAgents();
            })
            .catch(err => console.error('Error loading agents:', err));
    }

    function renderAgents() {
        elements.agentsListContainer.innerHTML = '';
        state.agents.forEach(agent => {
            const row = document.createElement('div');
            row.className = 'agent-row-card';
            
            row.innerHTML = `
                <div class="agent-avatar-icon" style="background-color: ${agent.color};">
                    ${agent.icon === 'A' ? 'A' : '<i class="fa-solid ' + getAgentAwesomeIcon(agent.name) + '"></i>'}
                </div>
                <div class="agent-details">
                    <span class="agent-name">${agent.name}</span>
                    <p class="agent-desc">${agent.description}</p>
                </div>
                <div class="agent-status">
                    <span class="agent-badge-status">${agent.status}</span>
                </div>
            `;
            elements.agentsListContainer.appendChild(row);
        });
    }

    function getAgentAwesomeIcon(agentName) {
        const icons = {
            'Grade Analyzer Agent': 'fa-lock',
            'Size Recommender Agent': 'fa-ruler-combined',
            'Style Advisor Agent': 'fa-wand-magic-sparkles',
            'Stock Checker Agent': 'fa-box-open'
        };
        return icons[agentName] || 'fa-robot';
    }

    // ==========================================
    // 7. EXCEL DRAG & DROP IMPORT (SHEETJS)
    // ==========================================
    function setupExcelImport() {
        const zone = elements.excelDragDropZone;
        const input = elements.excelFileInput;

        if (!zone || !input) return;

        zone.addEventListener('click', () => {
            input.click();
        });

        zone.addEventListener('dragover', (e) => {
            e.preventDefault();
            zone.classList.add('dragover');
        });

        zone.addEventListener('dragleave', () => {
            zone.classList.remove('dragover');
        });

        zone.addEventListener('drop', (e) => {
            e.preventDefault();
            zone.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                processExcelFile(files[0]);
            }
        });

        input.addEventListener('change', (e) => {
            const files = e.target.files;
            if (files.length > 0) {
                processExcelFile(files[0]);
            }
        });
    }

    function processExcelFile(file) {
        // Validate extension
        const ext = file.name.split('.').pop().toLowerCase();
        if (ext !== 'xlsx') {
            showToast('Hanya file bertipe .xlsx yang diperbolehkan!');
            return;
        }

        // Show processing state
        elements.uploadFeedbackDisplay.style.display = 'flex';

        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                const data = e.target.result;
                const workbook = XLSX.read(data, { type: 'binary' });
                
                // Parse first sheet
                const firstSheetName = workbook.SheetNames[0];
                const worksheet = workbook.Sheets[firstSheetName];
                
                // Convert worksheet to JSON rows
                const productsJson = XLSX.utils.sheet_to_json(worksheet);
                
                if (productsJson.length === 0) {
                    throw new Error('File Excel kosong atau tidak terstruktur dengan benar.');
                }

                // Send JSON payload to backend import endpoint
                uploadProductsJson(productsJson);

            } catch (err) {
                elements.uploadFeedbackDisplay.style.display = 'none';
                console.error(err);
                showToast('Gagal memproses file Excel: ' + err.message);
            }
        };

        reader.onerror = function() {
            elements.uploadFeedbackDisplay.style.display = 'none';
            showToast('Kesalahan saat membaca file.');
        };

        reader.readAsBinaryString(file);
    }

    function uploadProductsJson(products) {
        fetch('/api/products/import', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(products)
        })
        .then(res => res.json())
        .then(data => {
            elements.uploadFeedbackDisplay.style.display = 'none';
            if (data.status === 'success') {
                showToast(data.message);
                loadProducts(); // Reload products in UI
            } else {
                showToast('Impor gagal: ' + data.message);
            }
        })
        .catch(err => {
            elements.uploadFeedbackDisplay.style.display = 'none';
            console.error('Error importing products:', err);
            showToast('Gagal mengunggah data produk ke backend.');
        });
    }

    // Downloads a simulated CSV or standard Excel layout template
    function downloadExcelTemplate() {
        // Create basic structure with headers
        const headers = [
            { 'Nama Sepatu': 'Nike Air Jordan 1 High', 'Brand': 'Nike', 'Harga': '1950000', 'Grade': 'Grade A', 'Emoji': '👟' },
            { 'Nama Sepatu': 'Adidas Samba Classic', 'Brand': 'Adidas', 'Harga': '1450000', 'Grade': 'Grade A', 'Emoji': '👟' },
            { 'Nama Sepatu': 'Nike Air Max 90 Cacat Tipis', 'Brand': 'Nike', 'Harga': '950000', 'Grade': 'Grade B', 'Emoji': '👟' }
        ];

        // Create workbook using SheetJS
        const worksheet = XLSX.utils.json_to_sheet(headers);
        const workbook = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(workbook, worksheet, 'Daftar Produk');

        // Write as binary array
        const wopts = { bookType: 'xlsx', bookSST: false, type: 'binary' };
        const wbout = XLSX.write(workbook, wopts);

        function s2ab(s) {
            const buf = new ArrayBuffer(s.length);
            const view = new Uint8Array(buf);
            for (let i = 0; i !== s.length; ++i) view[i] = s.charCodeAt(i) & 0xFF;
            return buf;
        }

        // Trigger browser download
        const blob = new Blob([s2ab(wbout)], { type: 'application/octet-stream' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'templat_produk_sepatu.xlsx';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        
        showToast('Templat Excel berhasil diunduh.');
    }

    // ==========================================
    // 8. TOAST NOTIFICATIONS & HELPERS
    // ==========================================
    function showToast(message) {
        const toast = elements.toastNotification;
        if (!toast) return;

        toast.querySelector('.toast-message').textContent = message;
        toast.style.display = 'block';

        // Clear active timeout if any
        if (state.toastTimeout) {
            clearTimeout(state.toastTimeout);
        }

        state.toastTimeout = setTimeout(() => {
            toast.style.display = 'none';
        }, 3000);
    }

    function empty(val) {
        return val === undefined || val === null || val.trim() === '';
    }
});

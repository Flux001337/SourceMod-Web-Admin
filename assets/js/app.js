// Globale Helfer. Inline-Skripte sind per Content-Security-Policy nicht erlaubt.
(() => {
    // Dialoge öffnen und schließen (z. B. "Benutzer löschen?"). Als Funktion, damit nachgeladene Bereiche
    // (Live-Suche) ebenfalls verbunden werden. Ein data-expand-load im Dialog wird beim ersten Öffnen nachgeladen
    // (z. B. die ConVars eines Plugins), wie bei den aufklappbaren Zeilen.
    const bindDialogs = (root) => {
        root.querySelectorAll('[data-dialog-open]').forEach((button) => {
            button.addEventListener('click', () => {
                const dialog = document.getElementById(button.dataset.dialogOpen);
                if (dialog && typeof dialog.showModal === 'function') dialog.showModal();
                const target = dialog?.querySelector('[data-expand-load]');
                if (target && !target.dataset.loaded) {
                    target.dataset.loaded = '1';
                    loadDetails(target);
                }
            });
        });
        root.querySelectorAll('[data-dialog-close]').forEach((button) => {
            button.addEventListener('click', () => {
                const dialog = document.getElementById(button.dataset.dialogClose);
                if (dialog) dialog.close();
            });
        });
    };
    bindDialogs(document);

    // Formulare mit data-auto-submit (Sprachauswahl) senden sich bei einer Änderung selbst ab
    // (ohne JavaScript per Button in <noscript>).
    document.querySelectorAll('form[data-auto-submit] select').forEach((select) => {
        select.addEventListener('change', () => select.form.submit());
    });

    // Einheitliche Dropdowns: native Popup-Farben werden sonst vom System vorgegeben.
    document.querySelectorAll('select:not([multiple]):not([size])').forEach((select, index) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'language-dropdown';
        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'language-trigger';
        if (select.options.length === 0) return;
        const label = select.getAttribute('aria-label') || Array.from(select.labels || [], (item) => item.textContent.trim()).join(' ');
        trigger.id = (select.id || 'dropdown-' + index) + '-trigger';
        Array.from(select.labels || []).forEach((item) => { item.htmlFor = trigger.id; });
        // Alle Einträge liegen unsichtbar übereinander, nur der gewählte ist sichtbar: So ist der Button immer so breit
        // wie der längste Eintrag und springt beim Umschalten nicht.
        const value = document.createElement('span');
        value.className = 'language-value';
        const values = Array.from(select.options, (option) => {
            const item = document.createElement('span');
            item.textContent = option.textContent;
            return item;
        });
        value.append(...values);
        trigger.append(value);
        const update = () => {
            const current = select.options[select.selectedIndex]?.textContent || '';
            values.forEach((item, itemIndex) => item.classList.toggle('is-current', itemIndex === select.selectedIndex));
            trigger.disabled = select.disabled;
            trigger.setAttribute('aria-label', label + ': ' + current);
            entries.forEach((entry, entryIndex) => {
                entry.setAttribute('aria-selected', String(entryIndex === select.selectedIndex));
            });
        };
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        const list = document.createElement('div');
        list.id = 'language-options-' + index;
        list.className = 'language-options';
        list.setAttribute('role', 'listbox');
        list.setAttribute('aria-label', label);
        list.hidden = true;
        trigger.setAttribute('aria-controls', list.id);
        const entries = Array.from(select.options, (option, optionIndex) => {
            const entry = document.createElement('div');
            entry.className = 'language-option';
            entry.setAttribute('role', 'option');
            entry.setAttribute('aria-selected', String(option.selected));
            entry.tabIndex = -1;
            entry.textContent = option.textContent;
            // Maus und Tastatur verwenden denselben Fokus, damit nur ein Eintrag markiert ist.
            entry.addEventListener('pointerenter', () => entry.focus({ preventScroll: true }));
            entry.addEventListener('click', () => {
                select.selectedIndex = optionIndex;
                close(true);
                select.dispatchEvent(new Event('change', { bubbles: true }));
            });
            list.append(entry);
            return entry;
        });
        const close = (restoreFocus = false) => {
            list.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
            if (restoreFocus) trigger.focus();
        };
        const open = () => {
            list.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
            entries[select.selectedIndex].focus();
        };
        trigger.addEventListener('click', () => list.hidden ? open() : close());
        trigger.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                open();
            }
        });
        list.addEventListener('keydown', (event) => {
            const current = entries.indexOf(document.activeElement);
            let next = current;
            if (event.key === 'ArrowDown') next = (current + 1) % entries.length;
            else if (event.key === 'ArrowUp') next = (current - 1 + entries.length) % entries.length;
            else if (event.key === 'Home') next = 0;
            else if (event.key === 'End') next = entries.length - 1;
            else if (event.key === 'Escape') { event.preventDefault(); close(true); return; }
            else if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                entries[current].click();
                return;
            } else return;
            event.preventDefault();
            entries[next].focus();
        });
        select.addEventListener('change', update);
        select.form?.addEventListener('reset', () => setTimeout(update, 0));
        update();
        wrapper.append(trigger, list);
        select.after(wrapper);
        select.hidden = true;
        document.addEventListener('click', (event) => {
            if (!wrapper.contains(event.target)) close();
        });
        wrapper.addEventListener('focusout', (event) => {
            if (!wrapper.contains(event.relatedTarget)) close();
        });
    });

    // Server-Liste: Live-Status je Server nachladen (die Abfrage kann bis zum Timeout dauern).
    // Texte vom Gameserver nur als textContent einsetzen, nie als HTML.
    document.querySelectorAll('tr[data-server-status]').forEach((row) => {
        const field = (name) => row.querySelector('[data-field="' + name + '"]');
        const setText = (name, text) => {
            const element = field(name);
            if (element && text) element.textContent = text;
        };
        fetch(row.dataset.serverStatus, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => response.json())
            .then((status) => {
                const pill = field('status');
                pill.textContent = status.status_label || '–';
                pill.classList.add(status.online ? 'online' : 'offline');
                if (!status.online) return;
                setText('hostname', status.hostname);
                setText('map', status.map);
                setText('players', status.players);
                const icon = field('icon');
                if (icon && typeof status.icon === 'string' && status.icon.startsWith('assets/')) {
                    const image = document.createElement('img');
                    image.className = 'game-icon';
                    image.src = status.icon;
                    image.alt = '';
                    image.title = status.game || '';
                    icon.replaceWith(image);
                } else if (icon) {
                    icon.title = status.game || '';
                }
                const labels = field('labels');
                (status.labels || []).forEach((label) => {
                    const element = document.createElement('span');
                    element.className = 'pill pill-small';
                    element.textContent = label;
                    labels.append(element);
                });
            })
            .catch(() => {
                const pill = field('status');
                pill.textContent = '?';
            });
    });

    // Tabellenzeilen aufklappen (wie die Ban-Liste der Keks-Brigarde-Homepage): Der Button mit data-expand blendet die
    // Detailzeile (aria-controls) ein und aus. Ein Klick auf die Zeile selbst wirkt genauso -- außer auf Bedienelementen,
    // in Dialogen oder beim Markieren von Text. Ein Element mit data-expand-load in der Detailzeile wird beim ersten
    // Öffnen als JSON nachgeladen ({rows: [{label, value, link, error}], error}); Texte nur als textContent. Ohne Zeilen
    // steht dort data-expand-empty, falls gesetzt.
    const loadDetails = (target) => {
        fetch(target.dataset.expandLoad, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => response.json())
            .then((data) => {
                target.replaceChildren();
                if (data.error) {
                    const alert = document.createElement('p');
                    alert.className = 'alert error';
                    alert.textContent = data.error;
                    target.append(alert);
                    return;
                }
                if (!(data.rows || []).length && target.dataset.expandEmpty) {
                    const empty = document.createElement('p');
                    empty.className = 'muted';
                    empty.textContent = target.dataset.expandEmpty;
                    target.append(empty);
                    return;
                }
                const list = document.createElement('dl');
                list.className = 'expand-facts';
                (data.rows || []).forEach((item) => {
                    const entry = document.createElement('div');
                    const term = document.createElement('dt');
                    const value = document.createElement('dd');
                    term.textContent = item.label;
                    if (item.link && /^https?:\/\//i.test(item.value)) {
                        const link = document.createElement('a');
                        link.href = item.value;
                        link.className = 'external-link';
                        link.target = '_blank';
                        link.rel = 'noopener noreferrer';
                        link.textContent = item.value;
                        value.append(link);
                    } else if (item.dialog) {
                        // Öffnet einen Dialog der Seite (z. B. die ConVars eines Plugins).
                        const open = document.createElement('button');
                        open.type = 'button';
                        open.className = 'link-button';
                        open.dataset.dialogOpen = item.dialog;
                        open.textContent = item.value;
                        value.append(open);
                    } else {
                        value.textContent = item.value;
                    }
                    if (item.error) value.classList.add('text-danger');
                    entry.append(term, value);
                    list.append(entry);
                });
                target.append(list);
                bindDialogs(list);
            })
            .catch(() => {
                target.textContent = target.dataset.expandError || '?';
                delete target.dataset.loaded;
            });
    };
    const bindExpand = (root) => root.querySelectorAll('button[data-expand]').forEach((button) => {
        const details = document.getElementById(button.getAttribute('aria-controls'));
        const row = button.closest('tr');
        if (!details || !row) return;
        const name = (button.getAttribute('aria-label') || '').split(': ').slice(1).join(': ');
        const toggle = () => {
            const open = button.getAttribute('aria-expanded') !== 'true';
            const label = (open ? button.dataset.labelClose : button.dataset.labelOpen) || '';
            button.setAttribute('aria-expanded', String(open));
            button.title = label;
            button.setAttribute('aria-label', name ? label + ': ' + name : label);
            details.hidden = !open;
            row.classList.toggle('is-expanded', open);
            const target = details.querySelector('[data-expand-load]');
            if (open && target && !target.dataset.loaded) {
                target.dataset.loaded = '1';
                loadDetails(target);
            }
        };
        button.addEventListener('click', toggle);
        row.addEventListener('click', (event) => {
            if (event.target.closest('a, button, input, select, textarea, label, dialog, form') || String(window.getSelection()) !== '') return;
            toggle();
        });
    });
    bindExpand(document);

    // Filtern ohne Seitenwechsel (wie die Ban-Liste der Keks-Brigarde-Homepage): Beim Tippen, Absenden (Enter, Lupe),
    // bei geänderter Auswahl oder Checkbox und beim Leeren des Suchfelds wird die Seite mit den Formularwerten im Hintergrund
    // geladen und der Ergebnisbereich (ID in data-live-search) ersetzt -- samt Anzahl und Seiten, die der Server neu
    // berechnet. Die Adresse folgt per replaceState, damit Neuladen und Teilen dieselbe Ansicht zeigen. Leere Felder
    // bleiben aus der Adresse. Ohne JavaScript ein normales GET-Formular.
    document.querySelectorAll('form[data-live-search]').forEach((form) => {
        const reset = form.querySelector('[data-live-search-reset]');
        let controller = null;
        let lastUrl = window.location.pathname.split('/').pop() + window.location.search;
        const search = async () => {
            // Gleichnamige Felder: der letzte Wert gilt wie in PHP (verstecktes "0" vor einer Checkbox, die standardmäßig
            // an ist). Steht eine solche Checkbox auf ihrem Standard, bleibt sie ganz aus der Adresse.
            const params = new URLSearchParams();
            for (const [name, value] of new FormData(form)) {
                const text = String(value).trim();
                if (text === '') continue;
                if (name.endsWith('[]')) params.append(name, text);
                else params.set(name, text);
            }
            form.querySelectorAll('input[type="checkbox"][data-default-checked]').forEach((box) => {
                if (box.checked) params.delete(box.name);
            });
            const url = form.getAttribute('action') + '?' + params;
            if (url === lastUrl) return;
            lastUrl = url;
            // Zurücksetzen nur bei aktiver Suche oder einem Filter abweichend vom Standard (die Serverauswahl zählt nicht).
            if (reset) {
                reset.hidden = ![...form.elements].some((field) => (field.type === 'checkbox' && field.checked !== field.hasAttribute('data-default-checked'))
                    || (field.type === 'search' && field.value.trim() !== ''));
            }
            const results = document.getElementById(form.dataset.liveSearch);
            results?.classList.add('is-loading');
            controller?.abort();
            controller = new AbortController();
            try {
                const response = await fetch(url, { signal: controller.signal, headers: { Accept: 'text/html' }, credentials: 'same-origin' });
                if (!response.ok) throw new Error(String(response.status));
                const page = new DOMParser().parseFromString(await response.text(), 'text/html');
                const current = document.getElementById(form.dataset.liveSearch);
                const fresh = page.getElementById(form.dataset.liveSearch);
                if (!current || !fresh) throw new Error('missing results');
                current.replaceWith(fresh);
                bindDialogs(fresh);
                bindExpand(fresh);
                history.replaceState(null, '', url);
            } catch (error) {
                if (error.name !== 'AbortError') window.location.href = url;
            }
        };
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            clearTimeout(typingTimer);
            search();
        });
        // Beim Tippen kurz nach dem letzten Tastendruck suchen (jede Suche ist ein Abruf, bei den Plugins per RCON);
        // ein geleertes Feld sofort.
        let typingTimer = null;
        form.querySelectorAll('input[type="search"]').forEach((input) => input.addEventListener('input', () => {
            clearTimeout(typingTimer);
            if (input.value.trim() === '') search();
            else typingTimer = setTimeout(search, 400);
        }));
        form.querySelectorAll('select, input[type="checkbox"]').forEach((field) => field.addEventListener('change', search));
        // Sortierbare Spaltenköpfe (a[data-live-sort]) im Ergebnisbereich: Sortierung aus dem Link in die versteckten
        // Felder des Formulars (input[data-live-sort]) übernehmen und neu laden. Strg-/Mittelklick öffnet den Link normal.
        document.addEventListener('click', (event) => {
            const link = event.target.closest('a[data-live-sort]');
            if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            if (!document.getElementById(form.dataset.liveSearch)?.contains(link)) return;
            event.preventDefault();
            const params = new URL(link.href).searchParams;
            form.querySelectorAll('input[type="hidden"][data-live-sort]').forEach((field) => { field.value = params.get(field.name) ?? ''; });
            search();
        });
        // Zurücksetzen leert die Suche und stellt die Filter auf ihren Standard, die Serverauswahl bleibt (der Link selbst
        // gilt nur ohne JavaScript).
        reset?.addEventListener('click', (event) => {
            event.preventDefault();
            form.querySelectorAll('input[type="search"]').forEach((input) => { input.value = ''; });
            form.querySelectorAll('input[type="checkbox"]').forEach((input) => { input.checked = input.hasAttribute('data-default-checked'); });
            search();
        });
    });

    // RCON-Konsole: Online/Offline nachladen, Befehle ohne Neuladen senden ({entry: {time, command, response, error},
    // error}), Ausgabe unten halten, frühere Befehle mit Pfeil hoch/runter. Texte vom Server nur als textContent.
    // Ist der Server offline, werden Eingabe, Senden und Befehls-Buttons gesperrt (data-offline am Formular, damit das
    // Ende eines Sendevorgangs den Button nicht wieder freigibt). Solange er offline ist, wird alle 15 s erneut geprüft.
    const lockConsole = (offline) => {
        document.querySelectorAll('form[data-console-form]').forEach((form) => { form.dataset.offline = offline ? '1' : ''; });
        document.querySelectorAll('form[data-console-form] input[name="command"], form[data-console-form] button[type="submit"], button[data-console-button]')
            .forEach((element) => { element.disabled = offline; });
        document.querySelectorAll('[data-console-offline]').forEach((hint) => { hint.hidden = !offline; });
    };
    document.querySelectorAll('[data-console-status]').forEach((pill) => {
        let timer = null;
        const check = () => fetch(pill.dataset.consoleStatus, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => response.json())
            .then((status) => {
                pill.textContent = status.status_label || '–';
                pill.classList.toggle('online', Boolean(status.online));
                pill.classList.toggle('offline', !status.online);
                lockConsole(!status.online);
                if (!status.online && timer === null) {
                    timer = setInterval(check, 15000);
                } else if (status.online && timer !== null) {
                    clearInterval(timer);
                    timer = null;
                    document.querySelector('form[data-console-form] input[name="command"]')?.focus();
                }
            })
            // Status unbekannt: nichts sperren, der Server meldet beim Senden selbst einen Fehler.
            .catch(() => { pill.textContent = '–'; });
        check();
    });
    document.querySelectorAll('form[data-console-form]').forEach((form) => {
        const output = document.getElementById(form.dataset.consoleForm);
        const input = form.querySelector('input[name="command"]');
        const button = form.querySelector('button[type="submit"]');
        if (!output || !input) return;
        const scrollDown = () => { output.scrollTop = output.scrollHeight; };
        scrollDown();

        const history = [...output.querySelectorAll('[data-console-command]')].map((element) => element.textContent);
        let position = history.length;
        let draft = '';
        input.addEventListener('keydown', (event) => {
            if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') return;
            if (position === history.length) draft = input.value;
            position = event.key === 'ArrowUp' ? Math.max(0, position - 1) : Math.min(history.length, position + 1);
            input.value = position < history.length ? history[position] : draft;
            event.preventDefault();
        });

        const append = (entry) => {
            output.querySelector('.console-empty')?.setAttribute('hidden', '');
            const row = document.createElement('div');
            row.className = 'console-entry' + (entry.error ? ' is-error' : '');
            const time = document.createElement('span');
            time.className = 'console-time';
            time.textContent = '[' + (entry.time || new Date().toTimeString().slice(0, 8)) + ']';
            row.append(time, ' ');
            if (entry.command) {
                const command = document.createElement('span');
                command.className = 'console-command';
                command.textContent = '> ' + entry.command;
                row.append(command);
            }
            if (entry.response) {
                const response = document.createElement('pre');
                response.className = 'console-response';
                response.textContent = entry.response;
                row.append(response);
            }
            output.append(row);
            scrollDown();
        };

        // Sendet einen Befehl (aus der Eingabezeile oder von einem Button); die Eingabezeile wird nur bei eigenem Text geleert.
        const send = (command, fromInput) => {
            if (command === '' || button.disabled) return;
            const data = new FormData(form);
            data.set('command', command);
            data.set('send_command', '1');
            button.disabled = true;
            fetch(form.action, { method: 'POST', body: data, headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((response) => response.json())
                .then((result) => {
                    if (result.error) {
                        append({ command, response: result.error, error: true });
                        return;
                    }
                    append(result.entry);
                    if (history[history.length - 1] !== command) history.push(command);
                    if (fromInput) input.value = '';
                })
                .catch(() => append({ command, response: form.dataset.consoleError || '?', error: true }))
                .finally(() => {
                    position = history.length;
                    button.disabled = form.dataset.offline === '1';
                    input.focus();
                });
        };
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            send(input.value.trim(), true);
        });

        // Befehls-Buttons (console/*.json): ohne Platzhalter und Rückfrage sofort senden, sonst erst der Dialog mit einem
        // Feld je Platzhalter, Vorschau des Befehls und ggf. der Rückfrage.
        const dialog = document.getElementById('console-button-dialog');
        const part = (name) => dialog?.querySelector('[data-console-dialog-' + name + ']');
        // Keine Steuerzeichen, kein ";" (trennt in der Source-Konsole Befehle) und kein '"' (Anführungszeichen).
        const invalid = (value) => /[\x00-\x1f\x7f;"]/.test(value);
        const build = (spec, values) => {
            let command = spec.command;
            spec.args.forEach((arg) => {
                let value = (values[arg.name] ?? '').trim();
                if (value !== '' && spec.underscore) value = value.replace(/\s+/g, '_');
                if (value !== '' && arg.quote) value = '"' + value + '"';
                command = command.split('{' + arg.name + '}').join(value);
            });
            return command.replace(/\s+/g, ' ').trim();
        };
        // Auf- und zugeklappte Gruppen merken (nur in diesem Browser). Gruppen mit "open": true in der JSON starten immer
        // aufgeklappt; gemerkt wird nur bei den übrigen.
        const foldKey = 'smwa_console_fold';
        let folds = {};
        try { folds = JSON.parse(localStorage.getItem(foldKey) || '{}') || {}; } catch { folds = {}; }
        document.querySelectorAll('details[data-console-fold]').forEach((group) => {
            if (group.open) return;
            const name = group.dataset.consoleFold;
            if (typeof folds[name] === 'boolean') group.open = folds[name];
            group.addEventListener('toggle', () => {
                folds[name] = group.open;
                try { localStorage.setItem(foldKey, JSON.stringify(folds)); } catch { /* ohne Speicher nur für diese Seite */ }
            });
        });

        // Gruppen mit "requires" einblenden, wenn eines der Plugins läuft; ohne Antwort vom Server alle zeigen.
        const buttonColumn = document.querySelector('[data-console-requires]');
        const required = document.querySelectorAll('[data-console-group]');
        // Aus der Plugin-Liste geöffnete Gruppe (data-console-focus) in die Spalte scrollen und kurz hervorheben, sobald sie
        // sichtbar ist (Gruppen mit "requires" erst nach der Prüfung).
        const focusGroup = () => {
            const group = document.querySelector('[data-console-focus]');
            if (!buttonColumn || !group || group.hidden) return;
            if (buttonColumn.scrollHeight > buttonColumn.clientHeight) {
                buttonColumn.scrollTop = group.offsetTop - 8;
            } else {
                group.scrollIntoView({ block: 'start', behavior: 'smooth' });
            }
            group.classList.add('is-focused');
        };
        if (buttonColumn && required.length) {
            fetch(buttonColumn.dataset.consoleRequires, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((response) => response.json())
                .then((result) => required.forEach((group) => {
                    group.hidden = !result.error && result.available?.[group.dataset.consoleGroup] === false;
                }))
                .catch(() => required.forEach((group) => { group.hidden = false; }))
                .finally(focusGroup);
        } else {
            focusGroup();
        }

        // Vorschläge aus "status" für Felder mit "suggest" (player: #userid, steamid, ip), beim Öffnen des Dialogs geladen.
        const suggestions = (players, kind) => players
            .filter((player) => kind === 'player' || (kind === 'steamid' ? player.steamid : player.ip))
            .map((player) => ({
                value: kind === 'player' ? '#' + player.userid : (kind === 'steamid' ? player.steamid : player.ip),
                label: player.name + (kind === 'player' && player.bot ? ' (BOT)' : ''),
            }));
        // Zählt die Öffnungen des Dialogs, damit eine späte Antwort nicht in einen inzwischen anderen Dialog schreibt.
        let dialogRun = 0;
        const fillSuggestions = (spec, inputs) => {
            const run = dialogRun;
            const wanted = spec.args.filter((arg) => arg.suggest);
            if (!wanted.length || !dialog.dataset.players) return;
            fetch(dialog.dataset.players, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((response) => response.json())
                .then((result) => wanted.forEach((arg) => {
                    const field = inputs[arg.name];
                    if (!field || !dialog.open || run !== dialogRun) return;
                    let list = document.getElementById(field.id + '-options');
                    if (!list) {
                        list = document.createElement('datalist');
                        list.id = field.id + '-options';
                        field.after(list);
                        field.setAttribute('list', list.id);
                    }
                    // Vor die festen Vorschläge aus "options", in der Reihenfolge von "status".
                    list.prepend(...suggestions(result.players || [], arg.suggest).map((item) => {
                        const option = document.createElement('option');
                        option.value = item.value;
                        option.label = item.label;
                        return option;
                    }));
                }))
                .catch(() => {});
        };

        document.querySelectorAll('button[data-console-button]').forEach((trigger) => {
            let spec;
            try { spec = JSON.parse(trigger.dataset.consoleButton); } catch { return; }
            trigger.addEventListener('click', () => {
                if (!spec.args.length && !spec.confirm) {
                    send(spec.command, false);
                    return;
                }
                if (!dialog) return;
                part('title').textContent = spec.label;
                part('confirm').textContent = spec.confirm || '';
                part('confirm').hidden = !spec.confirm;
                part('error').hidden = true;
                const fields = part('fields');
                fields.replaceChildren();
                const inputs = {};
                spec.args.forEach((arg, index) => {
                    const id = 'console-arg-' + index;
                    const label = document.createElement('label');
                    label.htmlFor = id;
                    label.textContent = arg.label;
                    const field = document.createElement('input');
                    field.type = arg.type === 'number' ? 'number' : 'text';
                    field.id = id;
                    field.value = arg.default || '';
                    field.placeholder = arg.placeholder || '';
                    field.autocomplete = 'off';
                    field.spellcheck = false;
                    if (arg.options.length) {
                        const list = document.createElement('datalist');
                        list.id = id + '-options';
                        arg.options.forEach((option) => {
                            const entry = document.createElement('option');
                            entry.value = option;
                            list.append(entry);
                        });
                        field.setAttribute('list', list.id);
                        fields.append(label, field, list);
                    } else {
                        fields.append(label, field);
                    }
                    inputs[arg.name] = field;
                });
                const values = () => Object.fromEntries(Object.entries(inputs).map(([name, field]) => [name, field.value]));
                const preview = () => { part('preview').textContent = build(spec, values()); };
                Object.values(inputs).forEach((field) => field.addEventListener('input', preview));
                preview();
                part('form').onsubmit = (event) => {
                    event.preventDefault();
                    const missing = spec.args.find((arg) => arg.required && inputs[arg.name].value.trim() === '');
                    const bad = spec.args.find((arg) => invalid(inputs[arg.name].value));
                    if (missing || bad) {
                        part('error').textContent = missing
                            ? (dialog.dataset.errorRequired || '').replace('{label}', missing.label)
                            : dialog.dataset.errorValue || '';
                        part('error').hidden = false;
                        (inputs[(missing || bad).name]).focus();
                        return;
                    }
                    dialog.close();
                    send(build(spec, values()), false);
                };
                dialog.showModal();
                dialogRun += 1;
                fillSuggestions(spec, inputs);
                (Object.values(inputs)[0] || part('form').querySelector('button[type="submit"]')).focus();
            });
        });
    });

    // Seitenleiste auf kleinen Bildschirmen mit Escape schließen.
    const navToggle = document.getElementById('nav-toggle');
    if (navToggle) {
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') navToggle.checked = false;
        });
    }
})();

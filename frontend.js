(function () {
    function formatMoney(value) {
        var amount = Number(value || 0);
        return amount.toLocaleString('es-VE', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    // --- LÓGICA DE CLIENTES (REMITENTE Y DESTINATARIO) ---
    function field(wrapper, name) {
        return wrapper.querySelector('[data-client-field="' + name + '"]');
    }

    function setStatus(wrapper, message, state) {
        var status = wrapper.querySelector('[data-client-status]');
        if (!status) return;
        status.textContent = message || '';
        status.className = 'ecyg-status';
        if (state) {
            status.classList.add('is-' + state);
        }
    }

    function setClientReadonly(wrapper, readonly) {
        wrapper.querySelectorAll('[data-client-field]').forEach(function (input) {
            if (input.tagName === 'SELECT') {
                input.disabled = readonly;
            } else {
                input.readOnly = readonly;
            }
        });
    }

    function syncLookupToClient(wrapper) {
        var lookupType = wrapper.querySelector('[data-client-lookup-type]');
        var lookupNumber = wrapper.querySelector('[data-client-lookup-number]');
        var clientType = field(wrapper, 'document_type');
        var clientNumber = field(wrapper, 'document_number');

        if (lookupType && clientType && !clientType.disabled) {
            clientType.value = lookupType.value;
        }

        if (lookupNumber && clientNumber && !clientNumber.readOnly) {
            clientNumber.value = lookupNumber.value.trim().toUpperCase();
        }
    }

    function fillClient(wrapper, client) {
        Object.keys(client).forEach(function (key) {
            var input = field(wrapper, key);
            if (!input) return;
            input.value = client[key] || '';
        });
    }

    function clearClient(wrapper) {
        var idInput = wrapper.querySelector('[data-client-id]');
        if (idInput) idInput.value = '';

        wrapper.querySelectorAll('[data-client-field]').forEach(function (input) {
            input.value = '';
        });
        setClientReadonly(wrapper, false);
        syncLookupToClient(wrapper);
    }

    function lookupClient(wrapper) {
        var lookupType = wrapper.querySelector('[data-client-lookup-type]');
        var lookupNumber = wrapper.querySelector('[data-client-lookup-number]');

        if (!lookupType || !lookupNumber || !lookupNumber.value.trim()) {
            setStatus(wrapper, 'Introduce un documento para buscar.', 'warning');
            return;
        }

        setStatus(wrapper, ECYGGuias.messages.searching, '');

        var body = new FormData();
        body.append('action', 'ecyg_lookup_client');
        body.append('nonce', ECYGGuias.lookupNonce);
        body.append('document_type', lookupType.value);
        body.append('document_number', lookupNumber.value);

        fetch(ECYGGuias.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: body
        })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                if (!payload || !payload.success) throw new Error('lookup_failed');

                if (payload.data.found) {
                    var idInput = wrapper.querySelector('[data-client-id]');
                    if (idInput) idInput.value = payload.data.client_id;

                    fillClient(wrapper, payload.data.client);
                    setClientReadonly(wrapper, true);
                    setStatus(wrapper, ECYGGuias.messages.found, 'success');
                    return;
                }

                clearClient(wrapper);
                setStatus(wrapper, ECYGGuias.messages.notFound, 'warning');
            })
            .catch(function () {
                setStatus(wrapper, ECYGGuias.messages.error, 'error');
            });
    }

    // --- LÓGICA DE PAQUETES ---
    function reindexPackages(table) {
        table.querySelectorAll('[data-package-row]').forEach(function (row, index) {
            row.querySelectorAll('input').forEach(function (input) {
                if (input.name) {
                    input.name = input.name.replace(/packages\[\d+\]/, 'packages[' + index + ']');
                }
            });
        });
    }

    function updateRow(row) {
        var quantityInput = row.querySelector('[data-package-quantity]');
        var priceInput = row.querySelector('[data-package-price]');

        var quantity = Number(quantityInput ? quantityInput.value : 0);
        var price = Number(priceInput ? priceInput.value : 0);
        var total = quantity * price;

        var output = row.querySelector('[data-package-total]');
        if (output) {
            if (output.tagName === 'INPUT') {
                output.value = formatMoney(total);
            } else {
                output.textContent = formatMoney(total);
            }
        }
        return total;
    }

    function updateTotals(form) {
        var total = 0;
        form.querySelectorAll('[data-package-row]').forEach(function (row) {
            total += updateRow(row);
        });

        var grandTotal = form.querySelector('[data-grand-total]');
        if (grandTotal) {
            if (grandTotal.tagName === 'INPUT') {
                grandTotal.value = formatMoney(total);
            } else {
                grandTotal.textContent = formatMoney(total);
            }
        }
    }

    function clonePackageRow(form) {
        var table = form.querySelector('[data-package-table]');
        var firstRow = table.querySelector('[data-package-row]');
        if (!firstRow) return;

        var clone = firstRow.cloneNode(true);

        clone.querySelectorAll('input').forEach(function (input) {
            if (input.matches('[data-package-quantity]')) {
                input.value = '1';
            } else if (input.matches('[data-package-price]')) {
                input.value = '0.00';
            } else if (input.type !== 'button' && input.type !== 'submit') {
                input.value = '';
            }
        });

        var output = clone.querySelector('[data-package-total]');
        if (output) {
            if (output.tagName === 'INPUT') {
                output.value = '0,00';
            } else {
                output.textContent = '0,00';
            }
        }

        table.appendChild(clone);
        reindexPackages(table);
        updateTotals(form);
    }

    function removePackageRow(form, row) {
        var table = form.querySelector('[data-package-table]');
        var rows = table.querySelectorAll('[data-package-row]');

        if (rows.length === 1) {
            row.querySelectorAll('input').forEach(function (input) {
                if (input.matches('[data-package-quantity]')) {
                    input.value = '1';
                } else if (input.matches('[data-package-price]')) {
                    input.value = '0.00';
                } else if (input.type !== 'button' && input.type !== 'submit') {
                    input.value = '';
                }
            });
        } else {
            row.remove();
        }
        reindexPackages(table);
        updateTotals(form);
    }

    // --- ESCUCHADORES GENERALES E INTERFAZ ---
    document.addEventListener('DOMContentLoaded', function () {
        
        // Gestión del Panel de Reportes (Independiente del formulario)
        var toggleReportBtn = document.getElementById('ecyg-toggle-report-btn');
        var reportPanel = document.getElementById('ecyg-report-panel');
        var generateReportBtn = document.getElementById('ecyg-generate-report-btn');
        var pdfReportBtn = document.getElementById('ecyg-pdf-report-btn');
        var reportResults = document.getElementById('ecyg-report-results');

        if (toggleReportBtn && reportPanel) {
            toggleReportBtn.addEventListener('click', function () {
                var display = reportPanel.style.display;
                reportPanel.style.display = display === 'none' ? 'block' : 'none';
            });
        }

        if (generateReportBtn) {
            generateReportBtn.addEventListener('click', function () {
                var startVal = document.getElementById('ecyg-report-start').value;
                var endVal = document.getElementById('ecyg-report-end').value;

                if (!startVal || !endVal) {
                    alert('Por favor selecciona ambas fechas.');
                    return;
                }

                generateReportBtn.disabled = true;
                reportResults.innerHTML = '<p style="color:#666;">Buscando información en la base de datos...</p>';
                pdfReportBtn.style.display = 'none';

                var body = new FormData();
                body.append('action', 'ecyg_get_report');
                body.append('nonce', ECYGGuias.lookupNonce);
                body.append('start_date', startVal);
                body.append('end_date', endVal);

                fetch(ECYGGuias.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: body
                })
                .then(function (res) { return res.json(); })
                .then(function (payload) {
                    generateReportBtn.disabled = false;
                    if (payload.success) {
                        if (typeof payload.data === 'object' && payload.data.html) {
                            reportResults.innerHTML = payload.data.html;
                            if (payload.data.count > 0) {
                                pdfReportBtn.style.display = 'inline-block';
                            }
                        } else {
                            reportResults.innerHTML = payload.data;
                        }
                    } else {
                        reportResults.innerHTML = '<p style="color:red;">Error al procesar el reporte.</p>';
                    }
                })
                .catch(function () {
                    generateReportBtn.disabled = false;
                    reportResults.innerHTML = '<p style="color:red;">Error de conexión con el servidor.</p>';
                });
            });
        }

        if (pdfReportBtn) {
            pdfReportBtn.addEventListener('click', function () {
                var startVal = document.getElementById('ecyg-report-start').value;
                var endVal = document.getElementById('ecyg-report-end').value;
                if (startVal && endVal) {
                    // Lanza la petición HTTP directa que resolverá el PDF en una pestaña nueva
                    window.open(window.location.pathname + '?ecyg_download_report=1&start=' + startVal + '&end=' + endVal, '_blank');
                }
            });
        }

        // Eventos delegados del Formulario de Guías
        document.querySelectorAll('[data-guide-form]').forEach(function (form) {
            form.addEventListener('click', function (event) {
                if (event.target.matches('[data-client-lookup]')) {
                    event.preventDefault();
                    var wrapper = event.target.closest('.ecyg-panel');
                    if (wrapper) lookupClient(wrapper);
                }

                if (event.target.matches('[data-add-package]')) {
                    event.preventDefault();
                    clonePackageRow(form);
                }

                if (event.target.matches('[data-remove-package]')) {
                    event.preventDefault();
                    var row = event.target.closest('[data-package-row]');
                    if (row) removePackageRow(form, row);
                }
            });

            form.addEventListener('input', function (event) {
                if (event.target.matches('[data-client-lookup-number]')) {
                    var wrapper = event.target.closest('.ecyg-panel');
                    if (wrapper) {
                        var idInput = wrapper.querySelector('[data-client-id]');
                        if (idInput) idInput.value = '';
                        setClientReadonly(wrapper, false);
                        syncLookupToClient(wrapper);
                        setStatus(wrapper, '', '');
                    }
                }

                if (event.target.matches('[data-package-quantity], [data-package-price]')) {
                    updateTotals(form);
                }
            });

            form.addEventListener('keydown', function (event) {
                if (event.target.matches('[data-client-lookup-number]') && event.key === 'Enter') {
                    event.preventDefault();
                    var wrapper = event.target.closest('.ecyg-panel');
                    if (wrapper) lookupClient(wrapper);
                }
            });

            form.addEventListener('change', function (event) {
                if (event.target.matches('[data-client-lookup-type]')) {
                    var wrapper = event.target.closest('.ecyg-panel');
                    if (wrapper) {
                        var idInput = wrapper.querySelector('[data-client-id]');
                        if (idInput) idInput.value = '';
                        setClientReadonly(wrapper, false);
                        syncLookupToClient(wrapper);
                        setStatus(wrapper, '', '');
                    }
                }
            });

            form.addEventListener('submit', function () {
                form.querySelectorAll('[data-client-field]').forEach(function (input) {
                    if (input.disabled) input.disabled = false;
                });
            });

            form.querySelectorAll('.ecyg-panel').forEach(function (wrapper) {
                syncLookupToClient(wrapper);
            });

            updateTotals(form);
        });
    });
})();
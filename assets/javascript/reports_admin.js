/**
 * JS for admin/reports.php (period-field show/hide, quick-preview modal
 * fetch-and-render). Moved out of an inline <script> block.
 */

    (function () {
        var periodSelect = document.getElementById('reportPeriod');
        if (periodSelect) {
            var fields = {
                year: document.querySelector('.report-field-year'),
                month: document.querySelector('.report-field-month'),
                quarter: document.querySelector('.report-field-quarter'),
                week: document.querySelector('.report-field-week'),
                day: document.querySelector('.report-field-day'),
                from: document.querySelector('.report-field-from'),
                to: document.querySelector('.report-field-to')
            };

            function setVisible(fieldNode, visible) {
                if (!fieldNode) return;
                fieldNode.style.display = visible ? '' : 'none';
                var inputs = fieldNode.querySelectorAll('input, select');
                inputs.forEach(function (el) {
                    el.disabled = !visible;
                });
            }

            function syncPeriodFields() {
                var period = periodSelect.value;
                setVisible(fields.year, period === 'weekly' || period === 'monthly' || period === 'quarterly' || period === 'yearly');
                setVisible(fields.month, period === 'monthly');
                setVisible(fields.quarter, period === 'quarterly');
                setVisible(fields.week, period === 'weekly');
                setVisible(fields.day, period === 'daily');
                setVisible(fields.from, period === 'custom');
                setVisible(fields.to, period === 'custom');
            }

            periodSelect.addEventListener('change', syncPeriodFields);
            syncPeriodFields();
        }

        var modal = document.getElementById('quickReportModal');
        var modalBody = document.getElementById('quickReportModalBody');
        var modalTitle = document.getElementById('quickReportModalTitle');
        var modalClose = document.getElementById('quickReportModalClose');
        var quickViewButtons = document.querySelectorAll('.js-quick-view');

        if (!modal || !modalBody || !modalTitle || !quickViewButtons.length) {
            return;
        }

        function openModal() {
            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
        }

        function closeModal() {
            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('modal-open');
        }

        function setLoading() {
            modalBody.innerHTML = '<p class="reports-preview-loading">Loading report preview...</p>';
        }

        quickViewButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var previewUrl = btn.getAttribute('data-preview-url');
                var reportTitle = btn.getAttribute('data-report-title') || 'Quick Report Preview';
                if (!previewUrl) {
                    return;
                }

                modalTitle.textContent = reportTitle;
                setLoading();
                openModal();

                fetch(previewUrl + (previewUrl.indexOf('?') === -1 ? '?' : '&') + '_ts=' + Date.now(), {
                    method: 'GET',
                    credentials: 'same-origin'
                })
                    .then(function (res) {
                        if (!res.ok) {
                            throw new Error('Failed to load report preview.');
                        }
                        return res.text();
                    })
                    .then(function (html) {
                        modalBody.innerHTML = html;
                    })
                    .catch(function () {
                        modalBody.innerHTML = '<p class="reports-preview-error">Unable to load preview. Please try again.</p>';
                    });
            });
        });

        if (modalClose) {
            modalClose.addEventListener('click', closeModal);
        }

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && modal.classList.contains('show')) {
                closeModal();
            }
        });
    }());

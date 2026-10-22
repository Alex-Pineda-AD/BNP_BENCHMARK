(() => {
    'use strict';
    document.title = 'Registrar depósito · Conciliación bancaria';
    const el = id => document.getElementById(id);
    const form = el('deposit-form'), dialog = el('camera-dialog'), video = el('camera-video');
    let stream = null, worker = null, photo = null, previewUrl = null, ocr = null, photoReady = false, saved = false, busy = false, cameraGeneration = 0, readingGeneration = 0, lastCanvas = null;
    const manual = () => el('TIPO_CAPTURA').value === 'manual';
    const bank = () => el('ID_BANCO').value;
    const bankLabel = () => el('ID_BANCO').selectedOptions[0]?.textContent || '';
    const base = new URL('../../assets/vendors/tesseract/', document.baseURI).href;
    function notice(message, type = 'primary') { el('notice').className = `alert alert-light-${type}`; el('notice').textContent = message; }
    function completarCuentaBanco() {
        el('NUMERO_CUENTA').value = el('ID_BANCO').selectedOptions[0]?.dataset.cuenta?.trim() || '';
    }
    function updateSave() {
        completarCuentaBanco();
        el('TIPO_CAPTURA').disabled = busy;
        el('ID_BANCO').disabled = busy;
        el('open-camera').disabled = busy || !bank();
        el('rotate-photo').disabled = busy || !photo || !lastCanvas;
        if (el('photo-file')) el('photo-file').disabled = busy || !bank();
        el('save').disabled = busy || saved || !photo || !photoReady || !bank() || !el('NUMERO_CUENTA').value || !form.checkValidity() || !el('ID_CENTRO_VENTA').value || !el('CONFIRMADO').checked || form.dataset.authenticated !== '1';
        window.depositoCentro?.disable(busy || saved);
    }
    function closeCamera() {
        cameraGeneration++;
        if (stream) stream.getTracks().forEach(track => track.stop());
        stream = null; video.srcObject = null; el('capture').disabled = true;
        if (dialog.open) dialog.close();
    }
    function clearReading() {
        const captureType = el('TIPO_CAPTURA').value;
        const selectedBank = saved ? '' : bank();
        lastCanvas = null;
        const center = saved ? '' : el('ID_CENTRO_VENTA').value;
        photo = null; ocr = null; photoReady = false; saved = false;
        form.reset(); el('ID_BANCO').value = selectedBank; form.querySelectorAll('input, textarea').forEach(field => field.setCustomValidity('')); el('TIPO_CAPTURA').value = captureType; el('data-fields').disabled = true; el('ocr-text').textContent = '';
        window.depositoCentro?.restore(center);
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = null; el('preview').removeAttribute('src'); el('preview').hidden = true; el('empty-preview').hidden = false;
        updateSave();
    }
    el('ID_BANCO').addEventListener('change', () => {
        closeCamera(); clearReading();
        notice(bank() ? 'Banco seleccionado. Cargá una foto completa y legible del depósito.' : 'Seleccioná el banco para habilitar la carga de la foto.');
    });
    el('rotate-photo').addEventListener('click', () => {
        if (busy || !lastCanvas) return;
        readPhoto(rotateReceiptImage(lastCanvas), photo);
    });
    function stopReading(message) {
        readingGeneration++;
        if (worker) { worker.terminate().catch(() => {}); worker = null; }
        busy = false; el('data-fields').disabled = !photoReady;
        el('skip-ocr').hidden = true; el('ocr-progress').hidden = true;
        notice(message, 'warning'); updateSave();
    }
    el('skip-ocr').addEventListener('click', () => stopReading('Foto conservada. Completá los datos manualmente y confirmá antes de guardar.'));
    el('TIPO_CAPTURA').addEventListener('change', () => {
        closeCamera(); clearReading();
        notice(manual() ? 'Cargá la foto del recibo manuscrito y completá todos los campos a mano.' : 'Cargá una foto nítida del comprobante impreso para leer sus datos.');
    });
    el('open-camera').addEventListener('click', async () => {
        if (busy || !bank()) return;
        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
            notice('La cámara necesita HTTPS o localhost. Si usás un teléfono, abrí esta pantalla desde una dirección HTTPS.', 'warning'); return;
        }
        dialog.showModal(); el('capture').disabled = true; el('camera-status').textContent = 'Esperando permiso de la cámara…';
        const generation = ++cameraGeneration;
        try {
            const opened = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } } });
            if (generation !== cameraGeneration || !dialog.open) { opened.getTracks().forEach(track => track.stop()); return; }
            stream = opened; video.srcObject = stream; await video.play();
            if (generation !== cameraGeneration) return;
            el('camera-status').textContent = 'Acercá la cámara, mantenela estable y evitá cortar los bordes del comprobante.';
            el('capture').disabled = !video.videoWidth;
        } catch (error) {
            if (generation !== cameraGeneration) return;
            closeCamera();
            const messages = { NotAllowedError: 'Permití el acceso a la cámara en el navegador y volvé a intentarlo.', NotFoundError: 'No se encontró una cámara. Usá un dispositivo con cámara.', NotReadableError: 'La cámara está ocupada o no está disponible. Cerrá otras aplicaciones que la estén usando.' };
            notice(messages[error.name] || 'No se pudo abrir la cámara. Revisá los permisos e intentá nuevamente.', 'warning');
        }
    });
    el('close-camera').addEventListener('click', closeCamera);
    dialog.addEventListener('cancel', event => { event.preventDefault(); closeCamera(); });
    window.addEventListener('pagehide', () => { closeCamera(); if (worker) worker.terminate(); if (previewUrl) URL.revokeObjectURL(previewUrl); });
    document.addEventListener('visibilitychange', () => { if (document.hidden) closeCamera(); });
    el('capture').addEventListener('click', async () => {
        if (!stream || !video.videoWidth || busy) return;
        const canvas = document.createElement('canvas');
        const scale = Math.min(1, 2400 / Math.max(video.videoWidth, video.videoHeight));
        canvas.width = Math.round(video.videoWidth * scale); canvas.height = Math.round(video.videoHeight * scale);
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
        closeCamera();
        await readPhoto(canvas);
    });
    el('photo-file')?.addEventListener('change', async event => {
        const file = event.target.files[0];
        event.target.value = ''; // Allows choosing the same file again after a failed reading.
        if (!file || busy || !bank()) return;
        closeCamera();
        await readPhoto(file);
    });
    async function readPhoto(source, originalPhoto = null) {
        if (!bank() || busy) return;
        const generation = ++readingGeneration;
        let readingTimeout;
        clearReading(); busy = true; el('open-camera').disabled = true;
        updateSave();
        notice(manual() ? 'Preparando la foto del recibo manuscrito…' : 'Leyendo la foto. Los campos se completarán automáticamente al terminar.');
        el('ocr-progress').hidden = manual(); el('progress').value = 0;
        try {
            let canvas = source;
            if (source instanceof File) {
                if (!['image/jpeg', 'image/png', 'image/webp'].includes(source.type)) throw new Error('Seleccioná una imagen JPG, PNG o WebP.');
                if (source.size > 8 * 1024 * 1024) throw new Error('La imagen supera los 8 MB. Seleccioná una más pequeña.');
                const url = URL.createObjectURL(source);
                try {
                    const image = new Image(); image.src = url;
                    try { await image.decode(); } catch { throw new Error('No se pudo abrir la imagen. Seleccioná un archivo válido.'); }
                    if (image.naturalWidth < 250 || image.naturalHeight < 250 || image.naturalWidth * image.naturalHeight > 25000000) throw new Error('Usá una imagen de al menos 250 × 250 píxeles y hasta 25 megapíxeles.');
                    canvas = document.createElement('canvas');
                    const scale = Math.min(1, 2400 / Math.max(image.naturalWidth, image.naturalHeight));
                    canvas.width = Math.round(image.naturalWidth * scale); canvas.height = Math.round(image.naturalHeight * scale);
                    const context = canvas.getContext('2d');
                    context.fillStyle = '#fff'; context.fillRect(0, 0, canvas.width, canvas.height);
                    context.drawImage(image, 0, 0, canvas.width, canvas.height);
                    photo = source; // Keep the original file as evidence.
                } finally { URL.revokeObjectURL(url); }
            } else {
                if (canvas.width < 250 || canvas.height < 250) throw new Error('La cámara tiene muy poca resolución. Probá con otra cámara.');
                photo = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.94));
            }
            if (!photo || photo.size > 8 * 1024 * 1024) throw new Error('No se pudo preparar la foto. Volvé a tomarla.');
            if (originalPhoto) photo = originalPhoto;
            lastCanvas = canvas;
            previewUrl = URL.createObjectURL(originalPhoto ? await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', .94)) : photo); el('preview').src = previewUrl; el('preview').hidden = false; el('empty-preview').hidden = true;
            photoReady = true; // Foto validada: el OCR es una ayuda, no un requisito.
            // Los manuscritos conservan la foto, pero nunca pasan por OCR.
            if (manual()) {
                photoReady = true; el('data-fields').disabled = false;
                notice('Foto cargada. Llená todos los campos a mano, seleccioná el centro de venta y confirmá que los datos coinciden con la foto. Si no se distingue el recibo, volvé a fotografiarlo.', 'success');
                return;
            }
            el('skip-ocr').hidden = false;
            readingTimeout = setTimeout(() => { if (generation === readingGeneration) stopReading('La lectura tardó demasiado. La foto quedó cargada: podés completar todos los datos manualmente.'); }, 45000);
            if (!window.Tesseract) throw new Error('No se pudo cargar el lector de imágenes. Recargá la página e intentá nuevamente.');
            if (!worker) {
                const createdWorker = await Tesseract.createWorker('spa+eng', 1, {
                workerPath: base + 'worker.min.js', corePath: base + 'core', langPath: base + 'lang',
                logger: message => {
                    if (generation !== readingGeneration) return;
                    el('progress-label').textContent = message.status === 'recognizing text' ? 'Leyendo el comprobante…' : 'Preparando lector…';
                    el('progress').value = Math.round((message.progress || 0) * 100);
                },
            });
                if (generation !== readingGeneration) { createdWorker.terminate(); return; }
                worker = createdWorker;
            }
            const activeWorker = worker;
            await activeWorker.setParameters({ tessedit_pageseg_mode: '6' });
            let { data } = await activeWorker.recognize(prepareReceiptImage(canvas));
            if (generation !== readingGeneration) return;
            let assessment = DepositoParser.assessReceipt(data.text, data.confidence, bankLabel());
            if (assessment.missing.length) {
                await activeWorker.setParameters({ tessedit_pageseg_mode: '3' });
                const original = await activeWorker.recognize(canvas);
                if (generation !== readingGeneration) return;
                const alternate = DepositoParser.assessReceipt(original.data.text, original.data.confidence, bankLabel());
                if (alternate.score > assessment.score) { data = original.data; assessment = alternate; }
            }
            if (assessment.score < 30 && !assessment.mismatch) {
                await activeWorker.setParameters({ tessedit_pageseg_mode: '6' });
                const rotated = await activeWorker.recognize(prepareReceiptImage(rotateReceiptImage(canvas)));
                if (generation !== readingGeneration) return;
                const alternate = DepositoParser.assessReceipt(rotated.data.text, rotated.data.confidence, bankLabel());
                if (alternate.score > assessment.score) { data = rotated.data; assessment = alternate; }
            }
            ocr = { text: data.text, confidence: data.confidence };
            el('ocr-text').textContent = data.text || 'No se detectó texto.';
            for (const [key, value] of Object.entries(assessment.readable ? assessment.values : {})) {
                if (key !== 'BANCO' && key !== 'NUMERO_CUENTA' && el(key)) el(key).value = value;
            }
            el('data-fields').disabled = false;
            if (assessment.mismatch) {
                photoReady = false;
                notice('La foto parece corresponder a ' + assessment.values.BANCO + '. Seleccioná el banco correcto y volvé a cargarla.', 'warning');
            } else if (assessment.readable) notice(assessment.missing.length ? 'Lectura parcial. Completá los campos vacíos y corregí los datos comparándolos con la foto. Completá los campos marcados con * antes de guardar.' : 'Lectura completada. Revisá todos los campos, corregí cualquier error y confirmá antes de guardar.', assessment.missing.length ? 'warning' : 'success');
            else notice('No se pudieron extraer los datos con suficiente claridad. La foto quedó cargada: completá todos los campos manualmente. Si vos tampoco podés leer el comprobante, cargá una foto más clara.', 'warning');
        } catch (error) {
            if (generation !== readingGeneration) return;
            el('data-fields').disabled = !photoReady;
            notice(photoReady ? 'No se pudo realizar la lectura automática. La foto quedó cargada y podés ingresar todos los datos manualmente.' : (error.message || 'No se pudo cargar la foto. Volvé a intentarlo.'), photoReady ? 'warning' : 'danger');
            if (worker) { await worker.terminate().catch(() => {}); worker = null; }
        } finally {
            clearTimeout(readingTimeout);
            if (generation !== readingGeneration) return;
            el('skip-ocr').hidden = true;
            busy = false; el('open-camera').disabled = false; el('open-camera').textContent = 'Volver a tomar foto'; el('ocr-progress').hidden = true; updateSave();
        }
    }
    form.addEventListener('input', event => {
        if (event.target.matches('input[type="text"], input:not([type]), textarea') && event.target.required) {
            event.target.setCustomValidity(event.target.value.trim() ? '' : 'Completá este campo.');
        } if (event.target.id !== 'CONFIRMADO') el('CONFIRMADO').checked = false; updateSave(); });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || saved || !photoReady || !photo || !bank() || !el('ID_CENTRO_VENTA').value || !form.reportValidity() || form.dataset.authenticated !== '1') return;
        const data = new FormData(form);
        data.append('foto', photo, 'captura.jpg');
        data.append('ocr_text', (ocr?.text || '').slice(0, 12000));
        busy = true; updateSave(); el('open-camera').disabled = true; el('data-fields').disabled = true; el('save').textContent = 'Guardando…';
        const controller = new AbortController(); const timeout = setTimeout(() => controller.abort(), 30000);
        let successMessage = '';
        try {
            const response = await fetch('../../controllers/depositos.php', { method: 'POST', body: data, signal: controller.signal });
            const body = await response.json();
            if (!response.ok) throw new Error(body.message || 'No se pudo guardar. Intentá nuevamente.');
            successMessage = body.message;
            saved = true;
            clearReading(); // Clears the form, selected center, photograph and OCR after saving.
            if (el('photo-file')) el('photo-file').value = '';
            el('open-camera').textContent = 'Abrir cámara';
            notice(successMessage + ' Ya podés registrar otro depósito.', 'success');
        } catch (error) {
            notice(error.name === 'AbortError' ? 'La solicitud tardó demasiado. Se conservaron los datos; al reintentar se verificará si la referencia ya fue registrada.' : (error.message || 'No se pudo conectar. Tus datos siguen en pantalla.'), 'danger');
        } finally {
            clearTimeout(timeout); busy = false; el('data-fields').disabled = !photoReady; el('open-camera').disabled = false; el('save').textContent = 'Guardar depósito'; updateSave();
        }
        if (successMessage) {
            Swal.fire({
                icon: 'success',
                title: 'Depósito guardado correctamente',
                text: successMessage,
                confirmButtonText: 'Aceptar',
                buttonsStyling: false,
                customClass: { confirmButton: 'btn btn-primary' },
            });
        }
    });
    updateSave();
})();

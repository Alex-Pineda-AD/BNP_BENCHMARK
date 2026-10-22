(function (root) {
    'use strict';
    const normalize = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    function bankName(value) {
        const text = normalize(value).toUpperCase();
        return ['BAC', 'DAVIVIENDA', 'FICOHSA', 'ATLANTIDA', 'OCCIDENTE'].find(bank => text.includes(bank)) || '';
    }
    function money(value) {
        const token = String(value).trim().replace(/\s*([.,])\s*/g, '$1');
        if (/^\d{1,3}(,\d{3})+\.\d{2}$/.test(token)) return token.replace(/,/g, '');
        if (/^\d{1,3}(\.\d{3})+,\d{2}$/.test(token)) return token.replace(/\./g, '').replace(',', '.');
        if (/^\d{1,16}[.,]\d{2}$/.test(token)) return token.replace(',', '.');
        return '';
    }
    function parseReceipt(text, selectedBank = '') {
        const clean = normalize(text).replace(/\r/g, '').replace(/[\t ]+/g, ' ');
        const lines = clean.split('\n').map(line => line.trim()).filter(Boolean);
        const flat = clean.replace(/\s+/g, ' ');
        const detectedBank = bankName(flat);
        const bank = bankName(selectedBank) || detectedBank;
        const result = { BANCO: detectedBank, NUMERO_CUENTA: '', NUMERO_REFERENCIA: '', NUMERO_AUTORIZACION: '', REMITENTE: '', BENEFICIARIO: '', MONTO: '', MONEDA: '', COMENTARIO: '' };
        const field = pattern => {
            for (const line of lines) {
                const match = line.match(pattern);
                if (match) return match[1].trim();
            }
            return '';
        };
        if (bank === 'BAC') {
            result.REMITENTE = flat.match(/informamos\s+que\s+(.+?)\s+realizo\s+una\s+transferencia/i)?.[1] || '';
            const account = flat.match(/cuenta\s+(?:bancaria\s+)?(?:N[º°o0?]?\.?\s*)?([\d][\d -]{3,49})\s+a\s+nombre\s+de\s+(.+?)(?=\.?\s+Fecha\b)/i);
            if (account) { result.NUMERO_CUENTA = account[1].replace(/\s/g, ''); result.BENEFICIARIO = account[2].replace(/\.$/, '').trim(); }
            result.COMENTARIO = flat.match(/Detalle\s*:?\s*(.+?)\s+Referencia\b/i)?.[1] || '';
        } else {
            result.NUMERO_CUENTA = field(/^(?:N[Oº°.]*(?:\s*DE)?\s*CUENTA|CUENTA DESTINO|CUENTA(?:\s*N[Oº°.]*)?)[ .:]*([0-9]+(?:[-/][0-9]+)*)\s*$/i);
            const account = field(/^CUENTA[ .:]*([0-9]+\s+[A-Z][A-Z ]+)$/i).match(/^([0-9]+)\s+(.+)$/);
            if (account) { result.NUMERO_CUENTA = account[1]; result.BENEFICIARIO = account[2]; }
            result.BENEFICIARIO ||= field(/^(?:CUENTAHABIENTE|A NOMBRE DE)[ .:]*([^\d]+)$/i);
            if (bank === 'FICOHSA' || bank === 'ATLANTIDA') result.BENEFICIARIO ||= field(/^NOMBRE[ .:]+(.+)$/i);
            if (bank === 'OCCIDENTE') result.BENEFICIARIO ||= field(/^CLIENTE[ .:]+(.+)$/i);
            if (bank === 'DAVIVIENDA') result.REMITENTE = field(/^NOMBRE[ .:]+(.+)$/i);
            else result.REMITENTE = field(/^(?:NOMBRE\s*DEPOSITANTE|DEPOSITANTE|REFERENCIA DE PAGO)[ .:]+(.+)$/i);
            if (bank === 'ATLANTIDA') {
                result.REMITENTE ||= field(/^CLIENTE[ .:]+(.+)$/i);
                if (!result.BENEFICIARIO && lines.some(line => /^BANASUPRO$/i.test(line))) result.BENEFICIARIO = 'BANASUPRO';
                result.COMENTARIO = field(/^NOTAS[ .:]+(.+)$/i);
            }
        }
        result.NUMERO_REFERENCIA = field(/^(?:NUMERO DE REFERENCIA|REFERENCIA|N[Oº°.]*\s*DOCUMENTO)[ .:]*([A-Z0-9-]+)\s*$/i);
        if (bank === 'FICOHSA') result.NUMERO_REFERENCIA ||= field(/^(TT[A-Z0-9]{6,98})\s*$/i);
        result.NUMERO_AUTORIZACION = field(/^(?:N[Oº°.]*\s*(?:AUTORIZACION|APROBACION)|AUTORIZACION)[ .:]*([A-Z0-9-]+)\s*$/i);
        // Secuencia, Codigo, Terminal e Identidad no son referencias unicas.
        const amountLabels = bank === 'DAVIVIENDA' ? /^CANTIDAD[ .:]/i : /^(?:TOTAL\s*DEPOSITO|MONTO\s*DEPOSITADO|MONTO[ .:])/i;
        for (const line of lines.filter(line => amountLabels.test(line))) {
            const body = line.replace(/^(?:TOTAL\s*DEPOSITO|MONTO\s*DEPOSITADO|CANTIDAD|MONTO)[ .:]*/i, '').replace(/^(?:HNL|LPS|USD|US\$|L|\$)[ .:]*/i, '').replace(/^[*¥xX%]+\s*/, '');
            const match = body.match(/^(\d[\d., ]*[.,]\s*\d{2})(?=$|[^\d.,])/);
            if (match) result.MONTO = money(match[1]);
            if (result.MONTO) break;
        }
        if (/\bUSD\b|US\$/i.test(flat)) result.MONEDA = 'USD';
        else if (/\bHNL\b|\bLPS\s*\d|\bLEMPIRAS\b|\b(?:MONTO|DEPOSITO)\s*:?[ ]*L[ .]*\d/i.test(flat)) result.MONEDA = 'HNL';
        return result;
    }
    function assessReceipt(text, confidence, selectedBank = '') {
        const values = parseReceipt(text, selectedBank);
        const mismatch = !!values.BANCO && !!bankName(selectedBank) && values.BANCO !== bankName(selectedBank);
        const missing = ['NUMERO_CUENTA', 'REMITENTE', 'BENEFICIARIO', 'MONTO', 'MONEDA'].filter(key => !values[key]);
        const count = Object.entries(values).filter(([key, value]) => key !== 'BANCO' && value).length;
        return { values, missing, mismatch, score: mismatch ? -1 : count * 10 + (confidence || 0) / 100, readable: !mismatch && confidence >= 40 && count > 0 };
    }
    root.DepositoParser = { parseReceipt, assessReceipt, bankName };
    if (typeof module !== 'undefined') module.exports = root.DepositoParser;
})(typeof globalThis !== 'undefined' ? globalThis : window);

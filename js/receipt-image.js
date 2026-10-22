// Girar solo la copia de lectura: conservar siempre el archivo original.
window.rotateReceiptImage = function (source) {
    const rotated = document.createElement('canvas');
    rotated.width = source.height; rotated.height = source.width;
    const context = rotated.getContext('2d');
    context.translate(0, rotated.height); context.rotate(-Math.PI / 2);
    context.drawImage(source, 0, 0);
    return rotated;
};
// Work on a copy. The original camera photograph is always kept as evidence.
window.prepareReceiptImage = function (source) {
    const cleaned = document.createElement('canvas');
    cleaned.width = source.width; cleaned.height = source.height;
    const context = cleaned.getContext('2d', { willReadFrequently: true });
    context.drawImage(source, 0, 0);
    const pixels = context.getImageData(0, 0, cleaned.width, cleaned.height);
    // Remove red annotations beneath the logo area (e.g. underlined fields).
    for (let i = Math.floor(cleaned.height * .15) * cleaned.width * 4; i < pixels.data.length; i += 4) {
        const r = pixels.data[i], g = pixels.data[i + 1], b = pixels.data[i + 2];
        if (r > 130 && r > g * 1.35 && r > b * 1.35) pixels.data[i] = pixels.data[i + 1] = pixels.data[i + 2] = 255;
    }
    context.putImageData(pixels, 0, 0);
    const enlarged = document.createElement('canvas');
    const scale = Math.max(1, Math.min(3, 2400 / Math.max(source.width, source.height)));
    enlarged.width = Math.round(source.width * scale); enlarged.height = Math.round(source.height * scale);
    const target = enlarged.getContext('2d'); target.imageSmoothingEnabled = true; target.imageSmoothingQuality = 'high';
    target.drawImage(cleaned, 0, 0, enlarged.width, enlarged.height);
    return enlarged;
};

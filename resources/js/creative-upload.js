/**
 * Creative uploads (resources/views/livewire/creative-browser.blade.php, app/Livewire/CreativeBrowser.php).
 *
 * Files go straight from the browser to storage with a signed link, never through PHP. On Azure the
 * file is sent in 8 MB blocks (each retried on its own) and committed with a block list; the local
 * disk takes one PUT. Dimensions, duration and a poster frame are read here first; a video the browser
 * cannot decode (ProRes, some HEVC) still uploads, just without them.
 */

const BLOCK_SIZE = 8 * 1024 * 1024;
const TYPES = { jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp', gif: 'image/gif', mp4: 'video/mp4', m4v: 'video/mp4', mov: 'video/quicktime', webm: 'video/webm' };

function typeOf(file) {
    return Object.values(TYPES).includes(file.type) ? file.type : TYPES[file.name.split('.').pop().toLowerCase()] || file.type;
}

function withTimeout(promise, ms) {
    return Promise.race([promise, new Promise((resolve) => setTimeout(() => resolve(null), ms))]);
}

function posterFrom(source, width, height) {
    const scale = Math.min(1, 640 / Math.max(width, height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(width * scale));
    canvas.height = Math.max(1, Math.round(height * scale));
    canvas.getContext('2d').drawImage(source, 0, 0, canvas.width, canvas.height);

    return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.82));
}

async function imageMeta(file) {
    const bitmap = await createImageBitmap(file);
    const meta = { width: bitmap.width, height: bitmap.height, poster: await posterFrom(bitmap, bitmap.width, bitmap.height) };
    bitmap.close();

    return meta;
}

function videoMeta(file) {
    return new Promise((resolve) => {
        const video = document.createElement('video');
        const url = URL.createObjectURL(file);
        const meta = {};
        const done = () => { URL.revokeObjectURL(url); video.removeAttribute('src'); video.load(); resolve(meta); };
        video.muted = true;
        video.playsInline = true;
        video.preload = 'auto';
        video.onerror = done;
        video.onloadedmetadata = () => {
            if (Number.isFinite(video.duration)) meta.duration_ms = Math.round(video.duration * 1000);
            if (!video.videoWidth) return done();
            meta.width = video.videoWidth;
            meta.height = video.videoHeight;
            video.currentTime = Math.min(1, (video.duration || 0) / 10);
        };
        video.onseeked = async () => {
            meta.poster = await posterFrom(video, meta.width, meta.height).catch(() => null);
            done();
        };
        video.src = url;
    });
}

async function readMeta(file, type) {
    try {
        const meta = await withTimeout(type.startsWith('video/') ? videoMeta(file) : imageMeta(file), 10000);

        return meta || {};
    } catch {
        return {};
    }
}

function put(url, body, headers, onProgress, upload) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        upload.xhr = xhr;
        xhr.open('PUT', url);
        Object.entries(headers || {}).forEach(([name, value]) => xhr.setRequestHeader(name, value));
        xhr.upload.onprogress = (event) => event.lengthComputable && onProgress && onProgress(event.loaded / event.total);
        xhr.onload = () => (xhr.status >= 200 && xhr.status < 300 ? resolve() : reject(new Error(`Storage answered ${xhr.status}`)));
        xhr.onerror = () => reject(new Error('The connection dropped'));
        xhr.onabort = () => reject(new Error('cancelled'));
        xhr.send(body);
    });
}

async function retrying(task, upload, attempts = 3) {
    for (let attempt = 1; ; attempt++) {
        try {
            return await task();
        } catch (error) {
            if (upload.state === 'cancelled' || attempt >= attempts) throw error;
            await new Promise((resolve) => setTimeout(resolve, 1000 * attempt));
        }
    }
}

async function send(target, file, type, upload, track = true) {
    if (!target.blocks) {
        return retrying(() => put(target.url, file, { ...target.headers, 'Content-Type': type }, (p) => { if (track) upload.progress = p; }, upload), upload);
    }
    const ids = [];
    for (let start = 0; start < file.size; start += BLOCK_SIZE) {
        const id = btoa(String(ids.length).padStart(6, '0'));
        const chunk = file.slice(start, start + BLOCK_SIZE);
        ids.push(id);
        await retrying(() => put(`${target.url}&comp=block&blockid=${encodeURIComponent(id)}`, chunk, target.headers, (p) => {
            if (track) upload.progress = (start + p * chunk.size) / file.size;
        }, upload), upload);
    }
    const list = `<?xml version="1.0" encoding="utf-8"?><BlockList>${ids.map((id) => `<Latest>${id}</Latest>`).join('')}</BlockList>`;

    return retrying(() => put(`${target.url}&comp=blocklist`, list, { ...target.headers, 'Content-Type': 'application/xml', 'x-ms-blob-content-type': type }, null, upload), upload);
}

function creativeUploader($wire, chooseProduct) {
    return {
        uploads: [],
        over: false,
        notice: '',
        productId: '',
        busy: false,

        add(files) {
            this.notice = '';
            if (chooseProduct && !this.productId) {
                this.notice = 'Choose a product for these files first.';

                return;
            }
            for (const file of files) {
                this.uploads.push({ key: `${Date.now()}-${Math.random()}`, file, productId: this.productId, state: 'queued', progress: 0, error: '', id: null, xhr: null });
            }
            this.pump();
        },

        async pump() {
            if (this.busy) return;
            this.busy = true;
            let next;
            while ((next = this.uploads.find((upload) => upload.state === 'queued'))) {
                await this.run(next);
            }
            this.busy = false;
        },

        async run(upload) {
            const type = typeOf(upload.file);
            upload.state = 'preparing';
            upload.error = '';
            upload.progress = 0;
            try {
                const meta = await readMeta(upload.file, type);
                if (upload.state === 'cancelled') return;
                const response = await $wire.requestUpload({
                    name: upload.file.name, type, size: upload.file.size, product_id: upload.productId,
                    width: meta.width || null, height: meta.height || null, duration_ms: meta.duration_ms ?? null, poster: Boolean(meta.poster),
                });
                if (response.error) throw new Error(response.error);
                if (upload.state === 'cancelled') return $wire.cancelUpload(response.id);
                upload.id = response.id;
                upload.state = 'uploading';
                if (response.poster && meta.poster) {
                    await send(response.poster, meta.poster, 'image/jpeg', upload, false).catch(() => {});
                }
                if (upload.state === 'cancelled') return;
                await send(response.upload, upload.file, type, upload);
                const result = await $wire.finalizeUpload(upload.id);
                if (!result.ok) throw new Error(result.error);
                upload.progress = 1;
                upload.state = 'done';
            } catch (error) {
                if (upload.state === 'cancelled') return;
                upload.state = 'failed';
                upload.error = error.message || 'The upload failed.';
                if (upload.id) $wire.cancelUpload(upload.id);
                upload.id = null;
            }
        },

        cancel(upload) {
            upload.state = 'cancelled';
            upload.xhr?.abort();
            if (upload.id) $wire.cancelUpload(upload.id);
            upload.id = null;
        },

        retry(upload) {
            upload.state = 'queued';
            this.pump();
        },

        dismiss(upload) {
            this.uploads = this.uploads.filter((item) => item !== upload);
        },

        label(upload) {
            return {
                queued: 'Waiting',
                preparing: 'Reading file',
                uploading: `${Math.round(upload.progress * 100)}%`,
                done: 'Uploaded',
                failed: 'Failed',
                cancelled: 'Cancelled',
            }[upload.state];
        },
    };
}

/** One signed download per file, a moment apart so the browser keeps each one. */
window.downloadCreatives = async (urls) => {
    for (const url of urls) {
        const link = Object.assign(document.createElement('a'), { href: url, download: '' });
        document.body.appendChild(link);
        link.click();
        link.remove();
        await new Promise((resolve) => setTimeout(resolve, 400));
    }
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('creativeUploader', creativeUploader);
});

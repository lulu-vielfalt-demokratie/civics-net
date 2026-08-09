/**
 * PlatformSync Video-Editor – ffmpeg.wasm Export
 * Nutzt @ffmpeg/core-st (Single-Threaded, kein SharedArrayBuffer nötig)
 */

let ffmpeg = null;
window._resetFFmpeg = () => { ffmpeg = null; ffmpegLoaded = false; window._pveExportRunning = false; console.log("ffmpeg reset"); };
let ffmpegLoaded = false;
window._pveExportRunning = false;

function waitForGlobal(name, timeout = 60000) {
  return new Promise((resolve, reject) => {
    const start = Date.now();
    const check = () => {
      if (window[name]) { resolve(window[name]); return; }
      if (Date.now() - start > timeout) { reject(new Error(`${name} nicht geladen nach ${timeout}ms`)); return; }
      setTimeout(check, 200);
    };
    check();
  });
}

async function loadFFmpeg(onProgress) {
  if (ffmpegLoaded) return ffmpeg;
  onProgress('ffmpeg.wasm wird geladen…', 5);

  if (!window.FFmpeg) {
    await new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = 'https://unpkg.com/@ffmpeg/ffmpeg@0.11.6/dist/ffmpeg.min.js';
      s.onload = resolve;
      s.onerror = reject;
      document.head.appendChild(s);
    });
  }

  const FFmpegLib = await waitForGlobal('FFmpeg');
  const createFFmpeg = FFmpegLib.createFFmpeg;

  ffmpeg = createFFmpeg({
    log: false,
    progress: ({ ratio }) => {
      if (ratio > 0) onProgress('Video wird enkodiert…', 55 + Math.round(ratio * 40));
    },
    // Single-Threaded Core – kein SharedArrayBuffer nötig
    corePath: 'https://unpkg.com/@ffmpeg/core@0.11.0/dist/ffmpeg-core.js',
  });

  onProgress('ffmpeg-Core wird geladen…', 10);
  await ffmpeg.load();
  ffmpegLoaded = true;
  onProgress('Bereit.', 20);
  return ffmpeg;
}

window.PVEExport = async function(opts) {
  if (window._pveExportRunning) {
    opts.onError(new Error('Export läuft bereits – bitte warten'));
    return;
  }
  window._pveExportRunning = true;

  const {
    videoFile, inPt, outPt,
    audioTracks = [], audioMode = 'keep', origVol = 100,
    stripMeta = true, format = 'mp4',
    onProgress, onDone, onError,
  } = opts;

  try {
    const ff = await loadFFmpeg(onProgress);

    onProgress('Video wird eingelesen…', 25);
    const inputExt  = videoFile.name.split('.').pop() || 'mp4';
    const inputName = `input.${inputExt}`;
    const videoBuf  = await videoFile.arrayBuffer();
    ff.FS('writeFile', inputName, new Uint8Array(videoBuf));

    // Audiospuren laden
    const audioInputArgs   = [];
    const audioFilterParts = [];
    const audioMapParts    = [];

    for (let i = 0; i < audioTracks.length; i++) {
      const t    = audioTracks[i];
      const name = `audio_${i}.mp3`;
      onProgress(`Tonspur ${i + 1} wird geladen…`, 30 + i * 3);
      try {
        const resp = await fetch(t.url);
        const buf  = await resp.arrayBuffer();
        ff.FS('writeFile', name, new Uint8Array(buf));
        audioInputArgs.push('-i', name);
        audioFilterParts.push(`[${i + 1}:a]volume=${t.vol / 100}[a${i}]`);
        audioMapParts.push(`[a${i}]`);
      } catch (e) {
        console.warn(`Tonspur ${i} übersprungen:`, e);
      }
    }

    onProgress('Export wird vorbereitet…', 45);
    const outputName = `output.${format}`;
    const duration   = outPt - inPt;

    const args = [
      '-i', inputName,
      ...audioInputArgs,
      '-ss', String(inPt),
      '-t',  String(duration),
    ];

    // Audio-Modus
    if (audioMode === 'replace' && audioMapParts.length > 0) {
      const fc = [
        ...audioFilterParts,
        `${audioMapParts.join('')}amix=inputs=${audioMapParts.length}:duration=longest[aout]`,
      ].join(';');
      args.push('-filter_complex', fc, '-map', '0:v', '-map', '[aout]');
    } else if (audioMode === 'mix' && audioMapParts.length > 0) {
      const fc = [
        `[0:a]volume=${origVol / 100}[orig]`,
        ...audioFilterParts,
        `[orig]${audioMapParts.join('')}amix=inputs=${audioMapParts.length + 1}:duration=first[aout]`,
      ].join(';');
      args.push('-filter_complex', fc, '-map', '0:v', '-map', '[aout]');
    } else {
      args.push('-map', '0:v', '-map', '0:a?', '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '28', '-tune', 'zerolatency', '-c:a', 'copy');
    }

    if (stripMeta) {
      args.push('-map_metadata', '-1', '-map_chapters', '-1');
    }

    if (format === 'mp4') {
      args.push('-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '28', '-tune', 'zerolatency', '-c:a', 'copy');
    } else {
      args.push('-c:v', 'libvpx', '-b:v', '1M', '-c:a', 'copy');
    }

    args.push(outputName);

    onProgress('Video wird enkodiert…', 50);
    await ff.run(...args);

    onProgress('Wird finalisiert…', 96);
    const data = ff.FS('readFile', outputName);
    const mime = format === 'mp4' ? 'video/mp4' : 'video/webm';
    const blob = new Blob([data.buffer], { type: mime });

    try { ff.FS('unlink', inputName); } catch {}
    try { ff.FS('unlink', outputName); } catch {}
    audioTracks.forEach((_, i) => { try { ff.FS('unlink', `audio_${i}.mp3`); } catch {} });

    window._pveExportRunning = false;
    onProgress('✓ Fertig!', 100);
    onDone(blob, `civicos-export.${format}`);

  } catch (err) {
    window._pveExportRunning = false;
    console.error('[PVEExport]', err);
    onError(err);
  }
};

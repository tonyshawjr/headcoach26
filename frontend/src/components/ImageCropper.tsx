import { useState, useRef, useCallback, useEffect } from 'react';

/**
 * ImageCropper — Lets the user crop an image to a square before uploading.
 *
 * Shows the selected image with a draggable/resizable square crop area.
 * On confirm, crops to the selected region and returns a Blob.
 */

interface ImageCropperProps {
  /** The file the user selected */
  file: File;
  /** Called with the cropped image blob */
  onCrop: (blob: Blob) => void;
  /** Called when the user cancels */
  onCancel: () => void;
  /** Output size in pixels (default 400) */
  outputSize?: number;
}

export function ImageCropper({ file, onCrop, onCancel, outputSize = 400 }: ImageCropperProps) {
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const containerRef = useRef<HTMLDivElement>(null);
  const [imageUrl, setImageUrl] = useState<string>('');
  const [imgSize, setImgSize] = useState({ w: 0, h: 0 });
  const [crop, setCrop] = useState({ x: 0, y: 0, size: 150 });
  const [dragging, setDragging] = useState<'move' | 'resize' | null>(null);
  const dragStart = useRef({ mx: 0, my: 0, cx: 0, cy: 0, cs: 0 });

  // Load image
  useEffect(() => {
    const url = URL.createObjectURL(file);
    setImageUrl(url);

    const img = new Image();
    img.onload = () => {
      // Fit image to container (max 500px wide)
      const maxW = Math.min(500, window.innerWidth - 80);
      const scale = maxW / img.width;
      const displayW = Math.round(img.width * scale);
      const displayH = Math.round(img.height * scale);
      setImgSize({ w: displayW, h: displayH });

      // Default crop: centered square, 60% of the smaller dimension
      const minDim = Math.min(displayW, displayH);
      const cropSize = Math.round(minDim * 0.6);
      setCrop({
        x: Math.round((displayW - cropSize) / 2),
        y: Math.round((displayH - cropSize) / 2),
        size: cropSize,
      });
    };
    img.src = url;

    return () => URL.revokeObjectURL(url);
  }, [file]);

  const handleMouseDown = useCallback((e: React.MouseEvent, type: 'move' | 'resize') => {
    e.preventDefault();
    e.stopPropagation();
    setDragging(type);
    dragStart.current = { mx: e.clientX, my: e.clientY, cx: crop.x, cy: crop.y, cs: crop.size };
  }, [crop]);

  const handleMouseMove = useCallback((e: React.MouseEvent) => {
    if (!dragging) return;
    const dx = e.clientX - dragStart.current.mx;
    const dy = e.clientY - dragStart.current.my;

    if (dragging === 'move') {
      const newX = Math.max(0, Math.min(imgSize.w - crop.size, dragStart.current.cx + dx));
      const newY = Math.max(0, Math.min(imgSize.h - crop.size, dragStart.current.cy + dy));
      setCrop(c => ({ ...c, x: newX, y: newY }));
    } else if (dragging === 'resize') {
      const delta = Math.max(dx, dy);
      const newSize = Math.max(50, Math.min(
        Math.min(imgSize.w - crop.x, imgSize.h - crop.y),
        dragStart.current.cs + delta
      ));
      setCrop(c => ({ ...c, size: newSize }));
    }
  }, [dragging, imgSize, crop.x, crop.y, crop.size]);

  const handleMouseUp = useCallback(() => {
    setDragging(null);
  }, []);

  const handleCrop = useCallback(() => {
    if (!imageUrl || imgSize.w === 0) return;

    const img = new Image();
    img.onload = () => {
      const canvas = canvasRef.current;
      if (!canvas) return;

      canvas.width = outputSize;
      canvas.height = outputSize;
      const ctx = canvas.getContext('2d');
      if (!ctx) return;

      // Convert display coordinates to actual image coordinates
      const scaleX = img.width / imgSize.w;
      const scaleY = img.height / imgSize.h;

      const srcX = crop.x * scaleX;
      const srcY = crop.y * scaleY;
      const srcSize = crop.size * Math.max(scaleX, scaleY);

      ctx.drawImage(img, srcX, srcY, srcSize, srcSize, 0, 0, outputSize, outputSize);

      canvas.toBlob((blob) => {
        if (blob) onCrop(blob);
      }, 'image/jpeg', 0.92);
    };
    img.src = imageUrl;
  }, [imageUrl, imgSize, crop, outputSize, onCrop]);

  if (!imageUrl || imgSize.w === 0) {
    return (
      <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
        <p className="text-[var(--text-secondary)]">Loading image...</p>
      </div>
    );
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" onClick={onCancel}>
      <div
        className="bg-[var(--bg-surface)] border border-[var(--border)] rounded-2xl shadow-2xl p-6 max-w-[90vw]"
        onClick={(e) => e.stopPropagation()}
      >
        <h2 className="text-lg font-bold text-[var(--text-primary)] mb-4">Crop Avatar</h2>

        {/* Crop area */}
        <div
          ref={containerRef}
          className="relative mx-auto cursor-crosshair select-none overflow-hidden rounded-lg bg-black"
          style={{ width: imgSize.w, height: imgSize.h }}
          onMouseMove={handleMouseMove}
          onMouseUp={handleMouseUp}
          onMouseLeave={handleMouseUp}
        >
          {/* Full image (dimmed) */}
          <img
            src={imageUrl}
            alt="Crop preview"
            className="w-full h-full object-contain pointer-events-none"
            draggable={false}
          />

          {/* Dim overlay outside crop */}
          <div className="absolute inset-0 pointer-events-none" style={{
            background: `linear-gradient(to right, rgba(0,0,0,0.6) ${crop.x}px, transparent ${crop.x}px, transparent ${crop.x + crop.size}px, rgba(0,0,0,0.6) ${crop.x + crop.size}px)`,
          }} />
          <div className="absolute pointer-events-none" style={{
            left: crop.x, top: 0, width: crop.size, height: crop.y,
            background: 'rgba(0,0,0,0.6)',
          }} />
          <div className="absolute pointer-events-none" style={{
            left: crop.x, top: crop.y + crop.size, width: crop.size, bottom: 0,
            background: 'rgba(0,0,0,0.6)',
          }} />

          {/* Crop box */}
          <div
            className="absolute border-2 border-white cursor-move"
            style={{ left: crop.x, top: crop.y, width: crop.size, height: crop.size }}
            onMouseDown={(e) => handleMouseDown(e, 'move')}
          >
            {/* Grid lines */}
            <div className="absolute inset-0 pointer-events-none">
              <div className="absolute left-1/3 top-0 bottom-0 w-px bg-white/30" />
              <div className="absolute left-2/3 top-0 bottom-0 w-px bg-white/30" />
              <div className="absolute top-1/3 left-0 right-0 h-px bg-white/30" />
              <div className="absolute top-2/3 left-0 right-0 h-px bg-white/30" />
            </div>

            {/* Resize handle (bottom-right corner) */}
            <div
              className="absolute -bottom-1.5 -right-1.5 h-4 w-4 rounded-full bg-white border-2 border-[var(--accent-blue)] cursor-nwse-resize"
              onMouseDown={(e) => handleMouseDown(e, 'resize')}
            />
          </div>
        </div>

        {/* Hidden canvas for cropping */}
        <canvas ref={canvasRef} className="hidden" />

        {/* Actions */}
        <div className="flex justify-end gap-3 mt-4">
          <button
            onClick={onCancel}
            className="rounded-lg border border-[var(--border)] px-5 py-2.5 text-sm font-medium text-[var(--text-secondary)] hover:bg-[var(--bg-elevated)] transition-colors"
          >
            Cancel
          </button>
          <button
            onClick={handleCrop}
            className="rounded-lg bg-[var(--accent-blue)] px-5 py-2.5 text-sm font-medium text-white hover:bg-[var(--accent-blue)]/90 transition-colors"
          >
            Crop & Save
          </button>
        </div>
      </div>
    </div>
  );
}

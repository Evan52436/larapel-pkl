<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Media Gallery — Home Lab Storage</title>
  <link rel="stylesheet" href="{{ asset('css/gallery.css') }}">
</head>
<body>

  <!-- App Header & Batch Bar -->
  <header class="app-header">
    <div class="header-title">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
        <circle cx="8.5" cy="8.5" r="1.5"/>
        <polyline points="21 15 16 10 5 21"/>
      </svg>
      <span>Media Gallery</span>
    </div>

    <div class="batch-bar" id="batch-bar" style="display: none;">
      <span><strong id="selected-count">0</strong> selected</span>
      <button type="button" class="batch-btn" id="select-all-btn">Select All</button>
      <button type="button" class="batch-btn" id="batch-delete-btn">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
        Delete Selected
      </button>
      <button type="button" class="batch-btn" id="deselect-btn">Clear</button>
    </div>
  </header>

  <!-- Drag and Drop Overlay -->
  <div class="drag-overlay" id="drag-overlay">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
    <p style="margin-top: 0.5rem;">Drop photos or videos to upload</p>
  </div>

  <!-- Main Container -->
  <main class="main-container">

    <!-- Empty State -->
    <div class="empty-state" id="empty-state" style="{{ $media->total() === 0 ? 'display: flex;' : 'display: none;' }}">
      <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom: 1rem;">
        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
        <line x1="9" y1="9" x2="15" y2="15"/>
        <line x1="15" y1="9" x2="9" y2="15"/>
      </svg>
      <p>Nothing here yet — drop in your first photo or video</p>
    </div>

    <!-- Media Grid -->
    <div class="gallery-grid"
         id="gallery-grid"
         data-current-page="{{ $media->currentPage() }}"
         data-last-page="{{ $media->lastPage() }}">
      @foreach($media as $item)
        <div class="media-tile"
             data-id="{{ $item->id }}"
             data-uuid="{{ $item->uuid }}"
             data-type="{{ $item->type }}"
             data-name="{{ $item->original_name }}"
             data-url="{{ $item->url() }}"
             data-size="{{ $item->formattedSize() }}">

          @if($item->thumbnailUrl() || $item->type === 'photo')
            <img src="{{ $item->thumbnailUrl() ?? $item->url() }}" alt="{{ $item->original_name }}" loading="lazy">
          @else
            <div class="video-placeholder">
              <div class="play-badge">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
              </div>
              <span style="font-size:0.75rem; font-weight:600;">VIDEO</span>
            </div>
          @endif

          <div class="tile-scrim"></div>

          <!-- Multi-select Checkbox -->
          <div class="select-checkbox-wrapper">
            <div class="custom-checkbox" title="Select">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
          </div>

          <!-- Kebab Actions -->
          <div class="kebab-wrapper">
            <button type="button" class="kebab-btn" title="Options">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>
            </button>
            <div class="kebab-menu">
              <a href="{{ $item->url() }}" download="{{ $item->original_name }}" class="menu-item" target="_blank">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Download
              </a>
              <button type="button" class="menu-item delete-menu-item">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                Delete
              </button>
            </div>

            <!-- Inline Delete Confirmation Popover -->
            <div class="popover-confirm">
              <div class="popover-title">Delete this item?</div>
              <div class="popover-actions">
                <button type="button" class="popover-btn cancel-popover-btn">Cancel</button>
                <button type="button" class="popover-btn delete-confirm delete-confirm-btn">Delete</button>
              </div>
            </div>
          </div>

          <!-- Filename Scrim Banner -->
          <div class="tile-filename" title="{{ $item->original_name }}">{{ $item->original_name }}</div>
        </div>
      @endforeach
    </div>

    <!-- Infinite Scroll Sentinel -->
    <div class="infinite-scroll-sentinel" id="infinite-sentinel">
      <div class="spinner" id="scroll-spinner"></div>
    </div>
  </main>

  <!-- Uploading Status Floating Card -->
  <div class="upload-card" id="upload-card">
    <div class="upload-card-header">
      <span>Uploading files...</span>
    </div>
    <div class="upload-items-list" id="upload-items-list"></div>
  </div>

  <!-- Floating Action Button (FAB) -->
  <button type="button" class="fab" id="fab-upload" title="Upload Media">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
      <line x1="12" y1="5" x2="12" y2="19"/>
      <line x1="5" y1="12" x2="19" y2="12"/>
    </svg>
  </button>

  <!-- Hidden File Input -->
  <input type="file" id="gallery-file-input" multiple accept="image/*,video/*" style="display: none;">

  <!-- Modal Lightbox Preview -->
  <div class="lightbox-modal" id="lightbox-modal">
    <div class="lightbox-header">
      <div class="lightbox-title" id="lightbox-title">Preview</div>
      <div class="lightbox-actions">
        <a id="lightbox-download" href="#" download class="lightbox-btn primary">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Download
        </a>
        <button type="button" class="lightbox-btn" id="lightbox-close">Close</button>
      </div>
    </div>
    <div class="lightbox-content" id="lightbox-container"></div>
  </div>

  <script src="{{ asset('js/gallery.js') }}"></script>
</body>
</html>

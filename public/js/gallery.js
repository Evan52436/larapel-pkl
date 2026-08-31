document.addEventListener('DOMContentLoaded', () => {
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
  const activeFolderId = document.getElementById('active-folder-id')?.value || null;

  const fileInput = document.getElementById('gallery-file-input');
  const fabBtn = document.getElementById('fab-upload');
  const galleryGrid = document.getElementById('gallery-grid');
  const foldersGrid = document.getElementById('folders-grid');
  const emptyState = document.getElementById('empty-state');
  const batchBar = document.getElementById('batch-bar');
  const selectedCountEl = document.getElementById('selected-count');
  const selectAllBtn = document.getElementById('select-all-btn');
  const batchDeleteBtn = document.getElementById('batch-delete-btn');
  const deselectBtn = document.getElementById('deselect-btn');
  const uploadCard = document.getElementById('upload-card');
  const uploadItemsList = document.getElementById('upload-items-list');
  const sentinel = document.getElementById('infinite-sentinel');
  const spinner = document.getElementById('scroll-spinner');
  const dragOverlay = document.getElementById('drag-overlay');

  // New Folder Modal
  const newFolderBtn = document.getElementById('new-folder-btn');
  const newFolderModal = document.getElementById('new-folder-modal');
  const newFolderNameInput = document.getElementById('new-folder-name');
  const createFolderConfirmBtn = document.getElementById('create-folder-confirm-btn');
  const createFolderCancelBtn = document.getElementById('create-folder-cancel-btn');

  // Rename Modal
  const renameModal = document.getElementById('rename-modal');
  const renameInput = document.getElementById('rename-input');
  const renameSaveBtn = document.getElementById('rename-save-btn');
  const renameCancelBtn = document.getElementById('rename-cancel-btn');
  let itemToRename = null; // { type: 'file'|'folder', id: 123, element: HTMLElement }

  // Lightbox Modal
  const lightboxModal = document.getElementById('lightbox-modal');
  const lightboxTitle = document.getElementById('lightbox-title');
  const lightboxContainer = document.getElementById('lightbox-container');
  const lightboxDownload = document.getElementById('lightbox-download');
  const lightboxClose = document.getElementById('lightbox-close');

  let selectedIds = new Set();
  let currentPage = parseInt(galleryGrid?.dataset.currentPage || '1');
  let lastPage = parseInt(galleryGrid?.dataset.lastPage || '1');
  let isLoading = false;

  // Initialize Listeners
  attachTileListeners(document);
  attachFolderListeners(document);

  // New Folder Dialog Triggers
  if (newFolderBtn && newFolderModal) {
    newFolderBtn.addEventListener('click', () => {
      if (newFolderNameInput) newFolderNameInput.value = '';
      newFolderModal.classList.add('active');
      if (newFolderNameInput) newFolderNameInput.focus();
    });
  }

  if (createFolderCancelBtn && newFolderModal) {
    createFolderCancelBtn.addEventListener('click', () => {
      newFolderModal.classList.remove('active');
    });
  }

  if (createFolderConfirmBtn) {
    createFolderConfirmBtn.addEventListener('click', async () => {
      const name = newFolderNameInput?.value.trim();
      if (!name) return;

      try {
        const response = await fetch('/gallery/folders', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: JSON.stringify({
            name: name,
            parent_id: activeFolderId
          })
        });

        if (response.ok) {
          const res = await response.json();
          newFolderModal.classList.remove('active');
          window.location.reload();
        } else {
          alert('Failed to create folder.');
        }
      } catch (err) {
        console.error('Folder creation error:', err);
      }
    });
  }

  // Rename Modal Action Handlers
  if (renameCancelBtn && renameModal) {
    renameCancelBtn.addEventListener('click', () => {
      renameModal.classList.remove('active');
      itemToRename = null;
    });
  }

  if (renameSaveBtn) {
    renameSaveBtn.addEventListener('click', async () => {
      if (!itemToRename) return;
      const newName = renameInput?.value.trim();
      if (!newName) return;

      if (itemToRename.type === 'file') {
        await renameMediaItem(itemToRename.id, newName, itemToRename.element);
      } else if (itemToRename.type === 'folder') {
        await renameFolderItem(itemToRename.id, newName, itemToRename.element);
      }

      renameModal.classList.remove('active');
      itemToRename = null;
    });
  }

  // FAB Trigger
  if (fabBtn && fileInput) {
    fabBtn.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', (e) => {
      if (e.target.files && e.target.files.length > 0) {
        handleFilesUpload(Array.from(e.target.files));
        fileInput.value = '';
      }
    });
  }

  // Drag & Drop
  window.addEventListener('dragover', (e) => {
    e.preventDefault();
    if (dragOverlay) dragOverlay.classList.add('active');
  });

  ['dragleave', 'drop'].forEach((eventName) => {
    window.addEventListener(eventName, (e) => {
      e.preventDefault();
      if (dragOverlay) dragOverlay.classList.remove('active');
    });
  });

  window.addEventListener('drop', (e) => {
    if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
      handleFilesUpload(Array.from(e.dataTransfer.files));
    }
  });

  // Batch Toolbar Actions
  if (deselectBtn) deselectBtn.addEventListener('click', clearSelection);

  if (selectAllBtn && galleryGrid) {
    selectAllBtn.addEventListener('click', () => {
      const allTiles = galleryGrid.querySelectorAll('.media-tile');
      allTiles.forEach(tile => {
        const id = tile.dataset.id;
        selectedIds.add(id);
        tile.classList.add('selected');
        const checkbox = tile.querySelector('.custom-checkbox');
        if (checkbox) checkbox.classList.add('checked');
      });
      updateBatchBar();
    });
  }

  if (batchDeleteBtn) {
    batchDeleteBtn.addEventListener('click', async () => {
      if (selectedIds.size === 0) return;
      if (!confirm(`Delete ${selectedIds.size} selected item(s)?`)) return;

      const idsToDelete = Array.from(selectedIds);
      for (const id of idsToDelete) {
        await deleteMediaItem(id);
      }
      clearSelection();
    });
  }

  // Global Document Click for closing dropdowns/popovers
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.kebab-wrapper')) {
      document.querySelectorAll('.kebab-menu.active, .popover-confirm.active').forEach(el => {
        el.classList.remove('active');
        const parent = el.closest('.media-tile') || el.closest('.folder-card');
        if (parent) parent.classList.remove('menu-open');
      });
    }
  });

  // Infinite Scroll Observer
  if (sentinel && galleryGrid) {
    const observer = new IntersectionObserver((entries) => {
      if (entries[0].isIntersecting && !isLoading && currentPage < lastPage) {
        fetchNextPage();
      }
    }, { threshold: 0.1 });

    observer.observe(sentinel);
  }

  // Lightbox Close Handlers
  if (lightboxClose) lightboxClose.addEventListener('click', closeLightbox);
  if (lightboxModal) {
    lightboxModal.addEventListener('click', (e) => {
      if (e.target === lightboxModal || e.target.classList.contains('lightbox-content')) {
        closeLightbox();
      }
    });
  }
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && lightboxModal && lightboxModal.classList.contains('active')) {
      closeLightbox();
    }
  });

  // Helper Functions for Media Tiles
  function attachTileListeners(context) {
    const tiles = context.querySelectorAll('.media-tile');
    tiles.forEach(tile => {
      if (tile.dataset.bound) return;
      tile.dataset.bound = 'true';

      const id = tile.dataset.id;
      const checkbox = tile.querySelector('.custom-checkbox');
      const kebabBtn = tile.querySelector('.kebab-btn');
      const kebabMenu = tile.querySelector('.kebab-menu');
      const renameMenuBtn = tile.querySelector('.rename-menu-item');
      const deleteMenuBtn = tile.querySelector('.delete-menu-item');
      const popoverConfirm = tile.querySelector('.popover-confirm');
      const cancelPopoverBtn = tile.querySelector('.cancel-popover-btn');
      const deleteConfirmBtn = tile.querySelector('.delete-confirm-btn');

      if (checkbox) {
        checkbox.addEventListener('click', (e) => {
          e.stopPropagation();
          toggleSelection(id, tile, checkbox);
        });
      }

      if (kebabBtn && kebabMenu) {
        kebabBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          document.querySelectorAll('.kebab-menu.active, .popover-confirm.active').forEach(el => {
            if (el !== kebabMenu) el.classList.remove('active');
          });

          const isActive = kebabMenu.classList.toggle('active');
          tile.classList.toggle('menu-open', isActive);
          if (popoverConfirm) popoverConfirm.classList.remove('active');
        });
      }

      if (renameMenuBtn) {
        renameMenuBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          if (kebabMenu) kebabMenu.classList.remove('active');
          itemToRename = { type: 'file', id: id, element: tile };
          if (renameInput) renameInput.value = tile.dataset.name || '';
          if (renameModal) renameModal.classList.add('active');
          if (renameInput) renameInput.focus();
        });
      }

      if (deleteMenuBtn && popoverConfirm) {
        deleteMenuBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          if (kebabMenu) kebabMenu.classList.remove('active');
          popoverConfirm.classList.add('active');
        });
      }

      if (cancelPopoverBtn && popoverConfirm) {
        cancelPopoverBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          popoverConfirm.classList.remove('active');
          tile.classList.remove('menu-open');
        });
      }

      if (deleteConfirmBtn) {
        deleteConfirmBtn.addEventListener('click', async (e) => {
          e.stopPropagation();
          await deleteMediaItem(id);
        });
      }

      tile.addEventListener('click', (e) => {
        if (e.target.closest('.select-checkbox-wrapper') || e.target.closest('.kebab-wrapper')) {
          return;
        }
        openLightbox(tile.dataset);
      });
    });
  }

  // Helper Functions for Folder Cards
  function attachFolderListeners(context) {
    const folderCards = context.querySelectorAll('.folder-card');
    folderCards.forEach(card => {
      if (card.dataset.bound) return;
      card.dataset.bound = 'true';

      const id = card.dataset.id;
      const name = card.dataset.name;
      const kebabBtn = card.querySelector('.kebab-btn');
      const kebabMenu = card.querySelector('.kebab-menu');
      const renameMenuBtn = card.querySelector('.rename-folder-item');
      const deleteMenuBtn = card.querySelector('.delete-folder-item');
      const popoverConfirm = card.querySelector('.popover-confirm');
      const cancelPopoverBtn = card.querySelector('.cancel-popover-btn');
      const deleteConfirmBtn = card.querySelector('.delete-confirm-btn');

      // Click card to navigate into folder
      card.addEventListener('click', (e) => {
        if (e.target.closest('.kebab-wrapper')) return;
        window.location.href = `/gallery?folder_id=${id}`;
      });

      if (kebabBtn && kebabMenu) {
        kebabBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          document.querySelectorAll('.kebab-menu.active, .popover-confirm.active').forEach(el => {
            if (el !== kebabMenu) el.classList.remove('active');
          });

          const isActive = kebabMenu.classList.toggle('active');
          card.classList.toggle('menu-open', isActive);
          if (popoverConfirm) popoverConfirm.classList.remove('active');
        });
      }

      if (renameMenuBtn) {
        renameMenuBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          if (kebabMenu) kebabMenu.classList.remove('active');
          itemToRename = { type: 'folder', id: id, element: card };
          if (renameInput) renameInput.value = card.dataset.name || '';
          if (renameModal) renameModal.classList.add('active');
          if (renameInput) renameInput.focus();
        });
      }

      if (deleteMenuBtn && popoverConfirm) {
        deleteMenuBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          if (kebabMenu) kebabMenu.classList.remove('active');
          popoverConfirm.classList.add('active');
        });
      }

      if (cancelPopoverBtn && popoverConfirm) {
        cancelPopoverBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          popoverConfirm.classList.remove('active');
          card.classList.remove('menu-open');
        });
      }

      if (deleteConfirmBtn) {
        deleteConfirmBtn.addEventListener('click', async (e) => {
          e.stopPropagation();
          await deleteFolderItem(id);
        });
      }
    });
  }

  function toggleSelection(id, tile, checkbox) {
    if (selectedIds.has(id)) {
      selectedIds.delete(id);
      tile.classList.remove('selected');
      checkbox.classList.remove('checked');
    } else {
      selectedIds.add(id);
      tile.classList.add('selected');
      checkbox.classList.add('checked');
    }
    updateBatchBar();
  }

  function clearSelection() {
    selectedIds.clear();
    document.querySelectorAll('.media-tile.selected').forEach(tile => {
      tile.classList.remove('selected');
      const cb = tile.querySelector('.custom-checkbox');
      if (cb) cb.classList.remove('checked');
    });
    updateBatchBar();
  }

  function updateBatchBar() {
    if (!batchBar || !selectedCountEl) return;
    const count = selectedIds.size;
    selectedCountEl.textContent = count;
    batchBar.style.display = count > 0 ? 'flex' : 'none';
  }

  async function renameMediaItem(id, newName, tile) {
    try {
      const response = await fetch(`/gallery/media/${id}`, {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ original_name: newName })
      });

      if (response.ok) {
        tile.dataset.name = newName;
        const filenameEl = tile.querySelector('.tile-filename');
        if (filenameEl) {
          filenameEl.textContent = newName;
          filenameEl.title = newName;
        }
      } else {
        alert('Failed to rename file.');
      }
    } catch (err) {
      console.error('Rename file error:', err);
    }
  }

  async function renameFolderItem(id, newName, card) {
    try {
      const response = await fetch(`/gallery/folders/${id}`, {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ name: newName })
      });

      if (response.ok) {
        card.dataset.name = newName;
        const folderNameEl = card.querySelector('.folder-name');
        if (folderNameEl) folderNameEl.textContent = newName;
      } else {
        alert('Failed to rename folder.');
      }
    } catch (err) {
      console.error('Rename folder error:', err);
    }
  }

  async function deleteMediaItem(id) {
    const tile = galleryGrid.querySelector(`.media-tile[data-id="${id}"]`);
    try {
      const response = await fetch(`/gallery/media/${id}`, {
        method: 'DELETE',
        headers: {
          'X-CSRF-TOKEN': csrfToken,
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });

      if (response.ok) {
        if (tile) tile.remove();
        selectedIds.delete(id);
        updateBatchBar();
        checkEmptyState();
      } else {
        alert('Failed to delete item.');
      }
    } catch (err) {
      console.error('Delete error:', err);
    }
  }

  async function deleteFolderItem(id) {
    const card = foldersGrid?.querySelector(`.folder-card[data-id="${id}"]`);
    try {
      const response = await fetch(`/gallery/folders/${id}`, {
        method: 'DELETE',
        headers: {
          'X-CSRF-TOKEN': csrfToken,
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });

      if (response.ok) {
        if (card) card.remove();
        checkEmptyState();
      } else {
        alert('Failed to delete folder.');
      }
    } catch (err) {
      console.error('Delete folder error:', err);
    }
  }

  function checkEmptyState() {
    const remainingTiles = galleryGrid?.querySelectorAll('.media-tile');
    const remainingFolders = foldersGrid?.querySelectorAll('.folder-card');
    if ((!remainingTiles || remainingTiles.length === 0) && (!remainingFolders || remainingFolders.length === 0)) {
      if (emptyState) emptyState.style.display = 'flex';
    }
  }

  function handleFilesUpload(files) {
    if (!files.length) return;
    if (uploadCard) uploadCard.classList.add('active');
    if (emptyState) emptyState.style.display = 'none';

    files.forEach(file => {
      uploadFileStream(file);
    });
  }

  function uploadFileStream(file) {
    const rowId = 'upload-' + Math.random().toString(36).substr(2, 9);
    const row = document.createElement('div');
    row.className = 'upload-item-row';
    row.id = rowId;
    row.innerHTML = `
      <div class="upload-item-info">
        <span class="upload-item-name">${escapeHtml(file.name)}</span>
        <span class="upload-item-status">0%</span>
      </div>
      <div class="progress-track">
        <div class="progress-fill"></div>
      </div>
    `;
    if (uploadItemsList) uploadItemsList.appendChild(row);

    const formData = new FormData();
    formData.append('files[]', file);
    if (activeFolderId) {
      formData.append('folder_id', activeFolderId);
    }

    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/gallery/upload', true);
    xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.setRequestHeader('Accept', 'application/json');

    const progressFill = row.querySelector('.progress-fill');
    const statusText = row.querySelector('.upload-item-status');

    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable) {
        const percent = Math.round((e.loaded / e.total) * 100);
        if (progressFill) progressFill.style.width = percent + '%';
        if (statusText) statusText.textContent = percent + '%';
      }
    };

    xhr.onload = () => {
      if (xhr.status === 200) {
        if (statusText) statusText.textContent = 'Done';
        if (progressFill) progressFill.style.width = '100%';
        try {
          const res = JSON.parse(xhr.responseText);
          if (res.media && res.media.length > 0) {
            res.media.forEach(item => renderNewTile(item));
          }
        } catch (e) {
          console.error(e);
        }
        setTimeout(() => {
          row.remove();
          if (uploadItemsList && uploadItemsList.children.length === 0) {
            if (uploadCard) uploadCard.classList.remove('active');
          }
        }, 3000);
      } else {
        if (statusText) {
          statusText.textContent = 'Error';
          statusText.classList.add('error');
        }
        let errorDetail = 'Upload failed.';
        if (xhr.status === 413) {
          errorDetail = 'File size exceeds server upload limit (HTTP 413 Payload Too Large).';
        } else {
          try {
            const errRes = JSON.parse(xhr.responseText);
            if (errRes.errors) {
              errorDetail = Object.values(errRes.errors).flat().join(' ');
            } else if (errRes.message) {
              errorDetail = errRes.message;
            }
          } catch (e) {
            errorDetail = `Server error (${xhr.status}). Check PHP/Nginx limits.`;
          }
        }

        const errDiv = document.createElement('div');
        errDiv.className = 'upload-item-error-msg';
        errDiv.textContent = errorDetail;
        row.appendChild(errDiv);

        setTimeout(() => {
          row.remove();
          if (uploadItemsList && uploadItemsList.children.length === 0) {
            if (uploadCard) uploadCard.classList.remove('active');
          }
        }, 10000);
      }
    };

    xhr.onerror = () => {
      if (statusText) {
        statusText.textContent = 'Failed';
        statusText.classList.add('error');
      }
      const errDiv = document.createElement('div');
      errDiv.className = 'upload-item-error-msg';
      errDiv.textContent = 'Network or connection error during upload.';
      row.appendChild(errDiv);

      setTimeout(() => {
        row.remove();
        if (uploadItemsList && uploadItemsList.children.length === 0) {
          if (uploadCard) uploadCard.classList.remove('active');
        }
      }, 10000);
    };

    xhr.send(formData);
  }

  function renderNewTile(item) {
    if (!galleryGrid) return;
    const tileHtml = createTileHtml(item);
    galleryGrid.insertAdjacentHTML('afterbegin', tileHtml);
    const newTile = galleryGrid.querySelector(`.media-tile[data-id="${item.id}"]`);
    if (newTile) attachTileListeners(newTile.parentNode);
  }

  function createTileHtml(item) {
    const isVideo = item.type === 'video';
    const thumbUrl = item.thumbnail_url || (isVideo ? null : item.url);

    let mediaContent = '';
    if (thumbUrl) {
      mediaContent = `<img src="${escapeHtml(thumbUrl)}" alt="${escapeHtml(item.original_name)}" loading="lazy">`;
    } else {
      mediaContent = `
        <div class="video-placeholder">
          <div class="play-badge">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
          </div>
          <span style="font-size:0.75rem; font-weight:600;">VIDEO</span>
        </div>
      `;
    }

    return `
      <div class="media-tile"
           data-id="${item.id}"
           data-uuid="${item.uuid}"
           data-type="${item.type}"
           data-name="${escapeHtml(item.original_name)}"
           data-url="${escapeHtml(item.url)}"
           data-size="${escapeHtml(item.formatted_size)}">
        ${mediaContent}
        <div class="tile-scrim"></div>
        <div class="select-checkbox-wrapper">
          <div class="custom-checkbox" title="Select">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="20 6 9 17 4 12"/></svg>
          </div>
        </div>
        <div class="kebab-wrapper">
          <button type="button" class="kebab-btn" title="Options">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>
          </button>
          <div class="kebab-menu">
            <a href="${escapeHtml(item.url)}" download="${escapeHtml(item.original_name)}" class="menu-item" target="_blank">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
              Download
            </a>
            <button type="button" class="menu-item rename-menu-item">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
              Rename
            </button>
            <button type="button" class="menu-item delete-menu-item">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
              Delete
            </button>
          </div>
          <div class="popover-confirm">
            <div class="popover-title">Delete this item?</div>
            <div class="popover-actions">
              <button type="button" class="popover-btn cancel-popover-btn">Cancel</button>
              <button type="button" class="popover-btn delete-confirm delete-confirm-btn">Delete</button>
            </div>
          </div>
        </div>
        <div class="tile-filename" title="${escapeHtml(item.original_name)}">${escapeHtml(item.original_name)}</div>
      </div>
    `;
  }

  async function fetchNextPage() {
    isLoading = true;
    if (spinner) spinner.classList.add('active');

    try {
      const nextPage = currentPage + 1;
      let url = `/gallery?page=${nextPage}&format=json`;
      if (activeFolderId) url += `&folder_id=${activeFolderId}`;

      const res = await fetch(url, {
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });

      if (res.ok) {
        const data = await res.json();
        currentPage = data.current_page;
        lastPage = data.last_page;
        galleryGrid.dataset.currentPage = currentPage;

        if (data.data && data.data.length > 0) {
          data.data.forEach(item => {
            const html = createTileHtml(item);
            galleryGrid.insertAdjacentHTML('beforeend', html);
          });
          attachTileListeners(galleryGrid);
        }
      }
    } catch (e) {
      console.error('Infinite scroll error:', e);
    } finally {
      isLoading = false;
      if (spinner) spinner.classList.remove('active');
    }
  }

  function openLightbox(dataset) {
    if (!lightboxModal || !lightboxContainer) return;
    lightboxTitle.textContent = `${dataset.name} (${dataset.size})`;
    lightboxDownload.href = dataset.url;
    lightboxDownload.setAttribute('download', dataset.name);

    lightboxContainer.innerHTML = '';

    if (dataset.type === 'video') {
      const video = document.createElement('video');
      video.src = dataset.url;
      video.controls = true;
      video.autoplay = true;
      lightboxContainer.appendChild(video);
    } else {
      const img = document.createElement('img');
      img.src = dataset.url;
      img.alt = dataset.name;
      lightboxContainer.appendChild(img);
    }

    lightboxModal.classList.add('active');
  }

  function closeLightbox() {
    if (!lightboxModal) return;
    lightboxModal.classList.remove('active');
    if (lightboxContainer) {
      const video = lightboxContainer.querySelector('video');
      if (video) video.pause();
      lightboxContainer.innerHTML = '';
    }
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }
});

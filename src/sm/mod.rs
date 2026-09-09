/// Sm - Store Manager (Sm)
use core::ffi::c_void;
use crate::types::*;

pub struct SmStore {
    pub name: [u16; 64],
    pub size: u64,
    pub used: u64,
    pub store_handle: Handle,
    pub flags: u32,
}

pub struct SmProvider {
    pub provider_id: u32,
    pub callbacks: *mut c_void,
    pub next: *mut SmProvider,
}

static mut PROVIDER_LIST: *mut SmProvider = core::ptr::null_mut();

pub unsafe fn sm_init() -> NtStatus {
    PROVIDER_LIST = core::ptr::null_mut();
    STATUS_SUCCESS
}

pub unsafe fn sm_alloc(
    _store: *mut SmStore,
    _size: u64,
) -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn sm_free(
    _store: *mut SmStore,
    _offset: u64,
    _size: u64,
) -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn sm_reg_provider(
    provider_id: u32,
    callbacks: *mut c_void,
) -> NtStatus {
    let provider = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(core::mem::size_of::<SmProvider>()) as *mut SmProvider;
    if provider.is_null() { return STATUS_NO_MEMORY; }

    (*provider).provider_id = provider_id;
    (*provider).callbacks = callbacks;
    (*provider).next = PROVIDER_LIST;
    PROVIDER_LIST = provider;
    STATUS_SUCCESS
}

// ============================================================
// Win10 Sm: session-space store with bitmap allocator (Storp)
// ============================================================

pub const SM_STORE_FLAG_READY: u32 = 0x00000001;
pub const SM_STORE_FLAG_READ_ONLY: u32 = 0x00000002;
pub const SM_STORE_FLAG_COMPRESSED: u32 = 0x00000004;

pub const SM_PAGE_SIZE: u64 = 4096;

#[repr(C)]
pub struct SmSessionStore {
    pub name: [u16; 64],
    pub total_pages: u64,
    pub free_pages: u64,
    pub bitmap: *mut u64,
    pub bitmap_words: usize,
    pub base_pfn: u64,
    pub flags: u32,
    pub store_id: u32,
    pub next: *mut SmSessionStore,
}

static mut SM_STORE_LIST: *mut SmSessionStore = core::ptr::null_mut();
static SM_NEXT_STORE_ID: core::sync::atomic::AtomicU32 =
    core::sync::atomic::AtomicU32::new(1);

/// SmCreateStore - create a paged session store backed by a PFN range.
pub unsafe fn sm_create_store(
    name: *const u16,
    total_pages: u64,
    base_pfn: u64,
    store_out: *mut *mut SmSessionStore,
) -> NtStatus {
    if store_out.is_null() || total_pages == 0 {
        return STATUS_INVALID_PARAMETER;
    }
    let s = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<SmSessionStore>(),
    ) as *mut SmSessionStore;
    if s.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(s as *mut u8, 0, core::mem::size_of::<SmSessionStore>());
    if !name.is_null() {
        let mut i = 0;
        while i < 63 && *name.add(i) != 0 {
            (*s).name[i] = *name.add(i);
            i += 1;
        }
    }
    let words = ((total_pages + 63) / 64) as usize;
    let bitmap = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(words * 8) as *mut u64;
    if bitmap.is_null() {
        crate::mm::pool::ex_free_pool(s as *mut c_void);
        return STATUS_NO_MEMORY;
    }
    // All pages free: bitmap bit = 1 means allocated.
    core::ptr::write_bytes(bitmap as *mut u8, 0, words * 8);
    (*s).total_pages = total_pages;
    (*s).free_pages = total_pages;
    (*s).bitmap = bitmap;
    (*s).bitmap_words = words;
    (*s).base_pfn = base_pfn;
    (*s).flags = SM_STORE_FLAG_READY;
    (*s).store_id = SM_NEXT_STORE_ID.fetch_add(1, core::sync::atomic::Ordering::Relaxed);
    (*s).next = SM_STORE_LIST;
    SM_STORE_LIST = s;
    *store_out = s;
    STATUS_SUCCESS
}

/// SmAllocatePages - first-fit allocation from the store bitmap.
pub unsafe fn sm_allocate_pages(
    store: *mut SmSessionStore,
    page_count: u64,
    pfn_out: *mut u64,
) -> NtStatus {
    if store.is_null() || pfn_out.is_null() || page_count == 0 {
        return STATUS_INVALID_PARAMETER;
    }
    if (*store).free_pages < page_count {
        return STATUS_NO_MEMORY;
    }
    let total = (*store).total_pages;
    let mut start = 0u64;
    while start + page_count <= total {
        // Check run [start, start+page_count).
        let mut ok = true;
        let mut i = 0u64;
        while i < page_count {
            let bit = start + i;
            let w = (bit / 64) as usize;
            let b = (bit % 64) as u64;
            if (*(*store).bitmap.add(w) & (1u64 << b)) != 0 {
                ok = false;
                start = bit + 1;
                break;
            }
            i += 1;
        }
        if ok {
            let mut j = 0u64;
            while j < page_count {
                let bit = start + j;
                let w = (bit / 64) as usize;
                let b = (bit % 64) as u64;
                *(*store).bitmap.add(w) |= 1u64 << b;
                j += 1;
            }
            (*store).free_pages -= page_count;
            *pfn_out = (*store).base_pfn + start;
            return STATUS_SUCCESS;
        }
    }
    STATUS_NO_MEMORY
}

/// SmFreePages - return pages to the store.
pub unsafe fn sm_free_pages(
    store: *mut SmSessionStore,
    start_pfn: u64,
    page_count: u64,
) -> NtStatus {
    if store.is_null() || page_count == 0 {
        return STATUS_INVALID_PARAMETER;
    }
    if start_pfn < (*store).base_pfn {
        return STATUS_INVALID_PARAMETER;
    }
    let start = start_pfn - (*store).base_pfn;
    if start + page_count > (*store).total_pages {
        return STATUS_INVALID_PARAMETER;
    }
    let mut i = 0u64;
    while i < page_count {
        let bit = start + i;
        let w = (bit / 64) as usize;
        let b = (bit % 64) as u64;
        *(*store).bitmap.add(w) &= !(1u64 << b);
        i += 1;
    }
    (*store).free_pages += page_count;
    STATUS_SUCCESS
}

/// SmQueryStore - usage statistics.
pub unsafe fn sm_query_store(
    store: *mut SmSessionStore,
    total_pages: *mut u64,
    free_pages: *mut u64,
    flags: *mut u32,
) -> NtStatus {
    if store.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if !total_pages.is_null() {
        *total_pages = (*store).total_pages;
    }
    if !free_pages.is_null() {
        *free_pages = (*store).free_pages;
    }
    if !flags.is_null() {
        *flags = (*store).flags;
    }
    STATUS_SUCCESS
}

/// SmDestroyStore - tear down a store and free its bitmap.
pub unsafe fn sm_destroy_store(store: *mut SmSessionStore) -> NtStatus {
    if store.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let mut prev: *mut SmSessionStore = core::ptr::null_mut();
    let mut cur = SM_STORE_LIST;
    while !cur.is_null() {
        if cur == store {
            if prev.is_null() {
                SM_STORE_LIST = (*cur).next;
            } else {
                (*prev).next = (*cur).next;
            }
            if !(*cur).bitmap.is_null() {
                crate::mm::pool::ex_free_pool((*cur).bitmap as *mut c_void);
            }
            crate::mm::pool::ex_free_pool(cur as *mut c_void);
            return STATUS_SUCCESS;
        }
        prev = cur;
        cur = (*cur).next;
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}

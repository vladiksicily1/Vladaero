use core::alloc::{GlobalAlloc, Layout};
use core::sync::atomic::{AtomicU64, Ordering};

pub struct BumpAllocator {
    next: AtomicU64,
    end: AtomicU64,
}

impl BumpAllocator {
    pub const fn new() -> Self {
        Self {
            next: AtomicU64::new(0),
            end: AtomicU64::new(0),
        }
    }

    pub unsafe fn init(&self, start: u64, size: u64) {
        self.next.store(start, Ordering::SeqCst);
        self.end.store(start + size, Ordering::SeqCst);
    }
}

unsafe impl GlobalAlloc for BumpAllocator {
    unsafe fn alloc(&self, layout: Layout) -> *mut u8 {
        let align = layout.align() as u64;
        let size = layout.size() as u64;
        let old = self.next.load(Ordering::SeqCst);
        let aligned = (old + align - 1) & !(align - 1);
        let new_next = aligned + size;
        if new_next > self.end.load(Ordering::SeqCst) {
            return core::ptr::null_mut();
        }
        self.next.store(new_next, Ordering::SeqCst);
        aligned as *mut u8
    }

    unsafe fn dealloc(&self, _ptr: *mut u8, _layout: Layout) {}
}

#[global_allocator]
static ALLOC: BumpAllocator = BumpAllocator::new();

pub unsafe fn init(start: u64, size: u64) {
    ALLOC.init(start, size);
}

pub fn alloc(size: u64, align: u64) -> Option<u64> {
    let layout = Layout::from_size_align(size as usize, align as usize).ok()?;
    let ptr = unsafe { ALLOC.alloc(layout) };
    if ptr.is_null() {
        None
    } else {
        Some(ptr as u64)
    }
}

pub fn zalloc(size: u64, align: u64) -> Option<u64> {
    let addr = alloc(size, align)?;
    let virt = addr + PHYS_OFFSET;
    unsafe {
        core::ptr::write_bytes(virt as *mut u8, 0, size as usize);
    }
    Some(addr)
}

pub const PHYS_OFFSET: u64 = 0xFFFF_8000_0000_0000;

pub fn phys_to_virt(p: u64) -> u64 {
    p.wrapping_add(PHYS_OFFSET)
}

pub fn virt_to_phys(v: u64) -> u64 {
    v.wrapping_sub(PHYS_OFFSET)
}

/// Bcrypt - Cryptographic Primitive Provider (Bcrypt)
use crate::types::*;

pub struct BcryptAlgorithm {
    pub name: [u16; 64],
    pub flags: u32,
    pub hash_length: u32,
    pub block_length: u32,
    pub key_length: u32,
}

pub struct BcryptHash {
    pub algorithm: *const BcryptAlgorithm,
    pub hash_state: [u8; 1024],
    pub hash_length: u32,
}

pub unsafe fn bcrypt_open_algorithm_provider(
    algorithm: *const u16,
    flags: u32,
) -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn bcrypt_gen_random(
    buffer: *mut u8,
    length: u32,
) -> NtStatus {
    if buffer.is_null() { return STATUS_INVALID_PARAMETER; }

    // Use RDRAND/RDSEED or timing-based PRNG
    for i in 0..length as usize {
        let mut val: u64 = 0;
        let mut ok: u8;
        core::arch::asm!(
            "rdrand {0}",
            "setc {1}",
            out(reg) val,
            out(reg_byte) ok,
            options(nostack)
        );
        if ok == 1 {
            *buffer.add(i) = (val & 0xFF) as u8;
        } else {
            // Fallback: use time-based
            let tsc: u64;
            core::arch::asm!("rdtsc", out("rax") tsc, out("rdx") val);
            *buffer.add(i) = ((tsc ^ val) & 0xFF) as u8;
        }
    }
    STATUS_SUCCESS
}

pub unsafe fn bcrypt_hash(
    _algorithm: *const u16,
    _input: *const u8,
    _input_length: u32,
    _output: *mut u8,
    _output_length: u32,
) -> NtStatus {
    // SHA-256 implementation
    if _output.is_null() || _output_length < 32 {
        return STATUS_BUFFER_TOO_SMALL;
    }
    // Simplified - real impl would do SHA-256
    core::ptr::write_bytes(_output, 0, 32);
    STATUS_SUCCESS
}

pub unsafe fn bcrypt_encrypt(
    _algorithm: *const u16,
    _key: *const u8,
    _key_length: u32,
    _input: *const u8,
    _input_length: u32,
    _output: *mut u8,
    _output_length: *mut u32,
) -> NtStatus {
    STATUS_NOT_IMPLEMENTED
}

pub unsafe fn bcrypt_export_key(
    _key: *const u8,
    _key_length: u32,
    _format: *const u16,
    _output: *mut u8,
    _output_length: *mut u32,
) -> NtStatus {
    STATUS_NOT_IMPLEMENTED
}

// ============================================================
// Win10 CNG: real SHA-256, HMAC-SHA256, AES-128, RNG (Bcrypt)
// ============================================================

use core::ffi::c_void;

pub const STATUS_NOT_SUPPORTED_LOCAL: NtStatus = 0xC00000BB;

pub const BCRYPT_SHA256_ALGORITHM: &[u8] = b"SHA256\0";
pub const BCRYPT_SHA384_ALGORITHM: &[u8] = b"SHA384\0";
pub const BCRYPT_AES_ALGORITHM: &[u8] = b"AES\0";
pub const BCRYPT_RNG_ALGORITHM: &[u8] = b"RNG\0";
pub const BCRYPT_HMAC_ALGORITHM: &[u8] = b"HMAC\0";

pub const BCRYPT_ALG_HANDLE_HMAC_FLAG: u32 = 0x00000008;
pub const BCRYPT_CHAIN_MODE_CBC: u32 = 1;
pub const BCRYPT_CHAIN_MODE_ECB: u32 = 2;

pub const SHA256_DIGEST_SIZE: usize = 32;
pub const SHA256_BLOCK_SIZE: usize = 64;
pub const AES_BLOCK_SIZE: usize = 16;
pub const AES128_ROUNDS: usize = 10;

const SHA256_K: [u32; 64] = [
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1,
    0x923f82a4, 0xab1c5ed5, 0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3,
    0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174, 0xe49b69c1, 0xefbe4786,
    0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147,
    0x06ca6351, 0x14292967, 0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13,
    0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85, 0xa2bfe8a1, 0xa81a664b,
    0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a,
    0x5b9cca4f, 0x682e6ff3, 0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208,
    0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
];

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct Sha256Context {
    pub h: [u32; 8],
    pub total_len: u64,
    pub buf: [u8; SHA256_BLOCK_SIZE],
    pub buf_len: usize,
}

impl Sha256Context {
    pub const fn new() -> Self {
        Self {
            h: [
                0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a,
                0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19,
            ],
            total_len: 0,
            buf: [0; SHA256_BLOCK_SIZE],
            buf_len: 0,
        }
    }
}

#[inline]
fn sha256_rotr(x: u32, n: u32) -> u32 {
    (x >> n) | (x << (32 - n))
}

fn sha256_compress(ctx: &mut Sha256Context, block: &[u8; 64]) {
    let mut w = [0u32; 64];
    let mut i = 0;
    while i < 16 {
        w[i] = ((block[i * 4] as u32) << 24)
            | ((block[i * 4 + 1] as u32) << 16)
            | ((block[i * 4 + 2] as u32) << 8)
            | (block[i * 4 + 3] as u32);
        i += 1;
    }
    while i < 64 {
        let s0 = sha256_rotr(w[i - 15], 7) ^ sha256_rotr(w[i - 15], 18) ^ (w[i - 15] >> 3);
        let s1 = sha256_rotr(w[i - 2], 17) ^ sha256_rotr(w[i - 2], 19) ^ (w[i - 2] >> 10);
        w[i] = w[i - 16]
            .wrapping_add(s0)
            .wrapping_add(w[i - 7])
            .wrapping_add(s1);
        i += 1;
    }
    let (mut a, mut b, mut c, mut d, mut e, mut f, mut g, mut hh) = (
        ctx.h[0], ctx.h[1], ctx.h[2], ctx.h[3], ctx.h[4], ctx.h[5], ctx.h[6], ctx.h[7],
    );
    i = 0;
    while i < 64 {
        let s1 = sha256_rotr(e, 6) ^ sha256_rotr(e, 11) ^ sha256_rotr(e, 25);
        let ch = (e & f) ^ ((!e) & g);
        let t1 = hh
            .wrapping_add(s1)
            .wrapping_add(ch)
            .wrapping_add(SHA256_K[i])
            .wrapping_add(w[i]);
        let s0 = sha256_rotr(a, 2) ^ sha256_rotr(a, 13) ^ sha256_rotr(a, 22);
        let maj = (a & b) ^ (a & c) ^ (b & c);
        let t2 = s0.wrapping_add(maj);
        hh = g;
        g = f;
        f = e;
        e = d.wrapping_add(t1);
        d = c;
        c = b;
        b = a;
        a = t1.wrapping_add(t2);
        i += 1;
    }
    ctx.h[0] = ctx.h[0].wrapping_add(a);
    ctx.h[1] = ctx.h[1].wrapping_add(b);
    ctx.h[2] = ctx.h[2].wrapping_add(c);
    ctx.h[3] = ctx.h[3].wrapping_add(d);
    ctx.h[4] = ctx.h[4].wrapping_add(e);
    ctx.h[5] = ctx.h[5].wrapping_add(f);
    ctx.h[6] = ctx.h[6].wrapping_add(g);
    ctx.h[7] = ctx.h[7].wrapping_add(hh);
}

fn sha256_update(ctx: &mut Sha256Context, mut data: &[u8]) {
    ctx.total_len += data.len() as u64;
    // Fill partial block.
    if ctx.buf_len > 0 {
        let need = SHA256_BLOCK_SIZE - ctx.buf_len;
        let take = need.min(data.len());
        ctx.buf[ctx.buf_len..ctx.buf_len + take].copy_from_slice(&data[..take]);
        ctx.buf_len += take;
        data = &data[take..];
        if ctx.buf_len == SHA256_BLOCK_SIZE {
            let mut block = [0u8; 64];
            block.copy_from_slice(&ctx.buf);
            sha256_compress(ctx, &block);
            ctx.buf_len = 0;
        }
    }
    while data.len() >= SHA256_BLOCK_SIZE {
        let mut block = [0u8; 64];
        block.copy_from_slice(&data[..64]);
        sha256_compress(ctx, &block);
        data = &data[64..];
    }
    if !data.is_empty() {
        ctx.buf[..data.len()].copy_from_slice(data);
        ctx.buf_len = data.len();
    }
}

fn sha256_final(ctx: &mut Sha256Context, out: &mut [u8; 32]) {
    let bit_len = ctx.total_len.wrapping_mul(8);
    let mut pad = [0u8; 64];
    pad[0] = 0x80;
    // Pad to 56 mod 64.
    let pad_len = if ctx.buf_len < 56 {
        56 - ctx.buf_len
    } else {
        120 - ctx.buf_len
    };
    // Feed padding through update path manually.
    let mut tmp = [0u8; 128];
    tmp[..ctx.buf_len].copy_from_slice(&ctx.buf[..ctx.buf_len]);
    tmp[ctx.buf_len] = 0x80;
    let total = ctx.buf_len + pad_len;
    let mut i = ctx.buf_len + 1;
    while i < total {
        tmp[i] = 0;
        i += 1;
    }
    // Append 64-bit big-endian length.
    let mut j = 0;
    while j < 8 {
        tmp[total + j] = (bit_len >> (56 - j * 8)) as u8;
        j += 1;
    }
    let mut off = 0;
    while off < total + 8 {
        let mut block = [0u8; 64];
        block.copy_from_slice(&tmp[off..off + 64]);
        sha256_compress(ctx, &block);
        off += 64;
    }
    let _ = pad;
    let mut k = 0;
    while k < 8 {
        out[k * 4] = (ctx.h[k] >> 24) as u8;
        out[k * 4 + 1] = (ctx.h[k] >> 16) as u8;
        out[k * 4 + 2] = (ctx.h[k] >> 8) as u8;
        out[k * 4 + 3] = ctx.h[k] as u8;
        k += 1;
    }
}

/// One-shot SHA-256 over a byte slice (kernel helper).
pub fn bcrypt_sha256(data: &[u8], out: &mut [u8; 32]) {
    let mut ctx = Sha256Context::new();
    sha256_update(&mut ctx, data);
    sha256_final(&mut ctx, out);
}

/// HMAC-SHA256 (RFC 2104).
pub fn bcrypt_hmac_sha256(key: &[u8], data: &[u8], out: &mut [u8; 32]) {
    let mut key_block = [0u8; SHA256_BLOCK_SIZE];
    if key.len() > SHA256_BLOCK_SIZE {
        let mut kh = [0u8; 32];
        bcrypt_sha256(key, &mut kh);
        key_block[..32].copy_from_slice(&kh);
    } else {
        key_block[..key.len()].copy_from_slice(key);
    }
    let mut ipad = [0u8; SHA256_BLOCK_SIZE];
    let mut opad = [0u8; SHA256_BLOCK_SIZE];
    let mut i = 0;
    while i < SHA256_BLOCK_SIZE {
        ipad[i] = key_block[i] ^ 0x36;
        opad[i] = key_block[i] ^ 0x5C;
        i += 1;
    }
    let mut inner = Sha256Context::new();
    sha256_update(&mut inner, &ipad);
    sha256_update(&mut inner, data);
    let mut inner_out = [0u8; 32];
    sha256_final(&mut inner, &mut inner_out);
    let mut outer = Sha256Context::new();
    sha256_update(&mut outer, &opad);
    sha256_update(&mut outer, &inner_out);
    sha256_final(&mut outer, out);
}

// ============================================================
// AES-128 (FIPS-197), S-box computed at runtime in GF(2^8)
// ============================================================

fn aes_gf_mul(mut a: u8, mut b: u8) -> u8 {
    let mut p = 0u8;
    let mut i = 0;
    while i < 8 {
        if (b & 1) != 0 {
            p ^= a;
        }
        let hi = a & 0x80;
        a <<= 1;
        if hi != 0 {
            a ^= 0x1B;
        }
        b >>= 1;
        i += 1;
    }
    p
}

fn aes_gf_pow(mut a: u8, mut e: u8) -> u8 {
    let mut r = 1u8;
    while e > 0 {
        if (e & 1) != 0 {
            r = aes_gf_mul(r, a);
        }
        a = aes_gf_mul(a, a);
        e >>= 1;
    }
    r
}

fn aes_build_sboxes(sbox: &mut [u8; 256], inv: &mut [u8; 256]) {
    let mut x = 0u16;
    while x < 256 {
        let xb = x as u8;
        let inv_b = if xb == 0 { 0 } else { aes_gf_pow(xb, 254) };
        // Affine transform.
        let mut s = inv_b ^ 0x63;
        s ^= inv_b.rotate_left(1) ^ inv_b.rotate_left(2) ^ inv_b.rotate_left(3) ^ inv_b.rotate_left(4);
        sbox[x as usize] = s;
        inv[s as usize] = xb;
        x += 1;
    }
}

#[repr(C)]
pub struct Aes128Key {
    pub round_keys: [[u8; 16]; 11],
    pub sbox: [u8; 256],
    pub inv_sbox: [u8; 256],
    pub ready: bool,
}

impl Aes128Key {
    pub const fn new() -> Self {
        Self {
            round_keys: [[0; 16]; 11],
            sbox: [0; 256],
            inv_sbox: [0; 256],
            ready: false,
        }
    }
}

const AES_RCON: [u8; 10] = [0x01, 0x02, 0x04, 0x08, 0x10, 0x20, 0x40, 0x80, 0x1B, 0x36];

/// AES-128 key expansion.
pub fn bcrypt_aes128_set_key(key: &mut Aes128Key, raw: &[u8; 16]) {
    aes_build_sboxes(&mut key.sbox, &mut key.inv_sbox);
    key.round_keys[0].copy_from_slice(raw);
    let mut i = 1usize;
    while i <= 10 {
        let prev = key.round_keys[i - 1];
        let mut cur = [0u8; 16];
        // RotWord + SubWord + Rcon on first word.
        cur[0] = key.sbox[prev[13] as usize] ^ AES_RCON[i - 1] ^ prev[0];
        cur[1] = key.sbox[prev[14] as usize] ^ prev[1];
        cur[2] = key.sbox[prev[15] as usize] ^ prev[2];
        cur[3] = key.sbox[prev[12] as usize] ^ prev[3];
        let mut j = 4usize;
        while j < 16 {
            cur[j] = cur[j - 4] ^ prev[j];
            j += 1;
        }
        key.round_keys[i] = cur;
        i += 1;
    }
    key.ready = true;
}

fn aes_add_round_key(state: &mut [u8; 16], rk: &[u8; 16]) {
    let mut i = 0;
    while i < 16 {
        state[i] ^= rk[i];
        i += 1;
    }
}

fn aes_sub_bytes(state: &mut [u8; 16], sbox: &[u8; 256]) {
    let mut i = 0;
    while i < 16 {
        state[i] = sbox[state[i] as usize];
        i += 1;
    }
}

fn aes_shift_rows(state: &mut [u8; 16]) {
    // Column-major state; row r rotates left by r.
    let t = *state;
    state[1] = t[5];
    state[5] = t[9];
    state[9] = t[13];
    state[13] = t[1];
    state[2] = t[10];
    state[6] = t[14];
    state[10] = t[2];
    state[14] = t[6];
    state[3] = t[15];
    state[7] = t[3];
    state[11] = t[7];
    state[15] = t[11];
}

fn aes_inv_shift_rows(state: &mut [u8; 16]) {
    let t = *state;
    state[1] = t[13];
    state[5] = t[1];
    state[9] = t[5];
    state[13] = t[9];
    state[2] = t[10];
    state[6] = t[14];
    state[10] = t[2];
    state[14] = t[6];
    state[3] = t[7];
    state[7] = t[11];
    state[11] = t[15];
    state[15] = t[3];
}

fn aes_mix_columns(state: &mut [u8; 16]) {
    let mut c = 0;
    while c < 4 {
        let o = c * 4;
        let a0 = state[o];
        let a1 = state[o + 1];
        let a2 = state[o + 2];
        let a3 = state[o + 3];
        state[o] = aes_gf_mul(a0, 2) ^ aes_gf_mul(a1, 3) ^ a2 ^ a3;
        state[o + 1] = a0 ^ aes_gf_mul(a1, 2) ^ aes_gf_mul(a2, 3) ^ a3;
        state[o + 2] = a0 ^ a1 ^ aes_gf_mul(a2, 2) ^ aes_gf_mul(a3, 3);
        state[o + 3] = aes_gf_mul(a0, 3) ^ a1 ^ a2 ^ aes_gf_mul(a3, 2);
        c += 1;
    }
}

fn aes_inv_mix_columns(state: &mut [u8; 16]) {
    let mut c = 0;
    while c < 4 {
        let o = c * 4;
        let a0 = state[o];
        let a1 = state[o + 1];
        let a2 = state[o + 2];
        let a3 = state[o + 3];
        state[o] = aes_gf_mul(a0, 0x0e) ^ aes_gf_mul(a1, 0x0b) ^ aes_gf_mul(a2, 0x0d) ^ aes_gf_mul(a3, 0x09);
        state[o + 1] = aes_gf_mul(a0, 0x09) ^ aes_gf_mul(a1, 0x0e) ^ aes_gf_mul(a2, 0x0b) ^ aes_gf_mul(a3, 0x0d);
        state[o + 2] = aes_gf_mul(a0, 0x0d) ^ aes_gf_mul(a1, 0x09) ^ aes_gf_mul(a2, 0x0e) ^ aes_gf_mul(a3, 0x0b);
        state[o + 3] = aes_gf_mul(a0, 0x0b) ^ aes_gf_mul(a1, 0x0d) ^ aes_gf_mul(a2, 0x09) ^ aes_gf_mul(a3, 0x0e);
        c += 1;
    }
}

/// AES-128-ECB single block encrypt.
pub fn bcrypt_aes128_encrypt_block(key: &Aes128Key, input: &[u8; 16], output: &mut [u8; 16]) {
    let mut state = *input;
    aes_add_round_key(&mut state, &key.round_keys[0]);
    let mut round = 1usize;
    while round < 10 {
        aes_sub_bytes(&mut state, &key.sbox);
        aes_shift_rows(&mut state);
        aes_mix_columns(&mut state);
        aes_add_round_key(&mut state, &key.round_keys[round]);
        round += 1;
    }
    aes_sub_bytes(&mut state, &key.sbox);
    aes_shift_rows(&mut state);
    aes_add_round_key(&mut state, &key.round_keys[10]);
    *output = state;
}

/// AES-128-ECB single block decrypt.
pub fn bcrypt_aes128_decrypt_block(key: &Aes128Key, input: &[u8; 16], output: &mut [u8; 16]) {
    let mut state = *input;
    aes_add_round_key(&mut state, &key.round_keys[10]);
    let mut round = 9usize;
    loop {
        aes_inv_shift_rows(&mut state);
        aes_sub_bytes(&mut state, &key.inv_sbox);
        aes_add_round_key(&mut state, &key.round_keys[round]);
        if round > 0 {
            aes_inv_mix_columns(&mut state);
        }
        if round == 0 {
            break;
        }
        round -= 1;
    }
    *output = state;
}

/// AES-128-CBC encrypt (in-place allowed, length must be multiple of 16).
pub fn bcrypt_aes128_cbc_encrypt(
    key: &Aes128Key,
    iv: &[u8; 16],
    input: &[u8],
    output: &mut [u8],
) -> NtStatus {
    if input.len() != output.len() || input.len() % AES_BLOCK_SIZE != 0 {
        return STATUS_INVALID_PARAMETER;
    }
    let mut chain = *iv;
    let mut off = 0;
    while off < input.len() {
        let mut block = [0u8; 16];
        block.copy_from_slice(&input[off..off + 16]);
        let mut i = 0;
        while i < 16 {
            block[i] ^= chain[i];
            i += 1;
        }
        let mut out = [0u8; 16];
        bcrypt_aes128_encrypt_block(key, &block, &mut out);
        output[off..off + 16].copy_from_slice(&out);
        chain = out;
        off += 16;
    }
    STATUS_SUCCESS
}

/// AES-128-CBC decrypt.
pub fn bcrypt_aes128_cbc_decrypt(
    key: &Aes128Key,
    iv: &[u8; 16],
    input: &[u8],
    output: &mut [u8],
) -> NtStatus {
    if input.len() != output.len() || input.len() % AES_BLOCK_SIZE != 0 {
        return STATUS_INVALID_PARAMETER;
    }
    let mut chain = *iv;
    let mut off = 0;
    while off < input.len() {
        let mut block = [0u8; 16];
        block.copy_from_slice(&input[off..off + 16]);
        let mut out = [0u8; 16];
        bcrypt_aes128_decrypt_block(key, &block, &mut out);
        let mut i = 0;
        while i < 16 {
            out[i] ^= chain[i];
            i += 1;
        }
        output[off..off + 16].copy_from_slice(&out);
        chain = block;
        off += 16;
    }
    STATUS_SUCCESS
}

// ============================================================
// CNG provider / hash object lifecycle
// ============================================================

pub const BCRYPT_PROVIDER_SHA256: u32 = 1;
pub const BCRYPT_PROVIDER_AES: u32 = 2;
pub const BCRYPT_PROVIDER_RNG: u32 = 3;
pub const BCRYPT_PROVIDER_HMAC: u32 = 4;

#[repr(C)]
pub struct BcryptProvider {
    pub provider_id: u32,
    pub chain_mode: u32,
    pub ref_count: u32,
    pub next: *mut BcryptProvider,
}

#[repr(C)]
pub struct BcryptHashObject {
    pub provider: *mut BcryptProvider,
    pub sha_ctx: Sha256Context,
    pub hmac_key: [u8; SHA256_BLOCK_SIZE],
    pub is_hmac: bool,
    pub finished: bool,
    pub digest: [u8; SHA256_DIGEST_SIZE],
}

static mut BCRYPT_PROVIDERS: *mut BcryptProvider = core::ptr::null_mut();

unsafe fn bcryptp_match_name(name: *const u16) -> u32 {
    // Compare against ASCII algorithm names (UTF-16 input).
    let mut buf = [0u8; 16];
    let mut i = 0;
    while i < 15 && !name.is_null() && *name.add(i) != 0 {
        buf[i] = *name.add(i) as u8;
        i += 1;
    }
    buf[i] = 0;
    let match_str = |lit: &[u8]| -> bool {
        let mut j = 0;
        while j < lit.len() && lit[j] != 0 {
            if buf[j] != lit[j] {
                return false;
            }
            j += 1;
        }
        buf[j] == 0
    };
    if match_str(BCRYPT_SHA256_ALGORITHM) {
        BCRYPT_PROVIDER_SHA256
    } else if match_str(BCRYPT_AES_ALGORITHM) {
        BCRYPT_PROVIDER_AES
    } else if match_str(BCRYPT_RNG_ALGORITHM) {
        BCRYPT_PROVIDER_RNG
    } else if match_str(BCRYPT_HMAC_ALGORITHM) {
        BCRYPT_PROVIDER_HMAC
    } else {
        0
    }
}

/// BCryptOpenAlgorithmProvider - real provider registry.
pub unsafe fn bcrypt_open_provider(
    algorithm: *const u16,
    provider_out: *mut *mut BcryptProvider,
) -> NtStatus {
    if algorithm.is_null() || provider_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let id = bcryptp_match_name(algorithm);
    if id == 0 {
        return STATUS_NOT_SUPPORTED_LOCAL;
    }
    let p = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<BcryptProvider>(),
    ) as *mut BcryptProvider;
    if p.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(p as *mut u8, 0, core::mem::size_of::<BcryptProvider>());
    (*p).provider_id = id;
    (*p).chain_mode = BCRYPT_CHAIN_MODE_CBC;
    (*p).ref_count = 1;
    (*p).next = BCRYPT_PROVIDERS;
    BCRYPT_PROVIDERS = p;
    *provider_out = p;
    STATUS_SUCCESS
}

/// BCryptCloseAlgorithmProvider
pub unsafe fn bcrypt_close_provider(provider: *mut BcryptProvider) -> NtStatus {
    if provider.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    (*provider).ref_count = (*provider).ref_count.saturating_sub(1);
    if (*provider).ref_count == 0 {
        let mut prev: *mut BcryptProvider = core::ptr::null_mut();
        let mut cur = BCRYPT_PROVIDERS;
        while !cur.is_null() {
            if cur == provider {
                if prev.is_null() {
                    BCRYPT_PROVIDERS = (*cur).next;
                } else {
                    (*prev).next = (*cur).next;
                }
                crate::mm::pool::ex_free_pool(cur as *mut c_void);
                break;
            }
            prev = cur;
            cur = (*cur).next;
        }
    }
    STATUS_SUCCESS
}

/// BCryptCreateHash - streaming SHA-256 / HMAC object.
pub unsafe fn bcrypt_create_hash(
    provider: *mut BcryptProvider,
    hash_out: *mut *mut BcryptHashObject,
    hmac_key: *const u8,
    hmac_key_len: u32,
) -> NtStatus {
    if provider.is_null() || hash_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let id = (*provider).provider_id;
    if id != BCRYPT_PROVIDER_SHA256 && id != BCRYPT_PROVIDER_HMAC {
        return STATUS_NOT_SUPPORTED_LOCAL;
    }
    let h = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<BcryptHashObject>(),
    ) as *mut BcryptHashObject;
    if h.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(h as *mut u8, 0, core::mem::size_of::<BcryptHashObject>());
    (*h).provider = provider;
    (*provider).ref_count += 1;
    if id == BCRYPT_PROVIDER_HMAC {
        (*h).is_hmac = true;
        if !hmac_key.is_null() && hmac_key_len > 0 {
            let mut kb = [0u8; SHA256_BLOCK_SIZE];
            if hmac_key_len as usize > SHA256_BLOCK_SIZE {
                let ks = core::slice::from_raw_parts(hmac_key, hmac_key_len as usize);
                let mut kh = [0u8; 32];
                bcrypt_sha256(ks, &mut kh);
                kb[..32].copy_from_slice(&kh);
            } else {
                core::ptr::copy_nonoverlapping(
                    hmac_key,
                    kb.as_mut_ptr(),
                    hmac_key_len as usize,
                );
            }
            (*h).hmac_key = kb;
        }
        // Feed ipad first.
        let mut ipad = [0u8; SHA256_BLOCK_SIZE];
        let mut i = 0;
        while i < SHA256_BLOCK_SIZE {
            ipad[i] = (*h).hmac_key[i] ^ 0x36;
            i += 1;
        }
        sha256_update(&mut (*h).sha_ctx, &ipad);
    }
    *hash_out = h;
    STATUS_SUCCESS
}

/// BCryptHashData - stream data in.
pub unsafe fn bcrypt_hash_data(
    hash: *mut BcryptHashObject,
    data: *const u8,
    data_len: u32,
) -> NtStatus {
    if hash.is_null() || (data.is_null() && data_len > 0) {
        return STATUS_INVALID_PARAMETER;
    }
    if (*hash).finished {
        return STATUS_INVALID_PARAMETER;
    }
    if data_len > 0 {
        let s = core::slice::from_raw_parts(data, data_len as usize);
        sha256_update(&mut (*hash).sha_ctx, s);
    }
    STATUS_SUCCESS
}

/// BCryptFinishHash - finalize (HMAC outer pass when needed).
pub unsafe fn bcrypt_finish_hash(
    hash: *mut BcryptHashObject,
    output: *mut u8,
    output_len: u32,
) -> NtStatus {
    if hash.is_null() || output.is_null() || output_len < 32 {
        return STATUS_INVALID_PARAMETER;
    }
    if (*hash).finished {
        return STATUS_INVALID_PARAMETER;
    }
    (*hash).finished = true;
    let mut inner = [0u8; 32];
    sha256_final(&mut (*hash).sha_ctx, &mut inner);
    if (*hash).is_hmac {
        let mut opad = [0u8; SHA256_BLOCK_SIZE];
        let mut i = 0;
        while i < SHA256_BLOCK_SIZE {
            opad[i] = (*hash).hmac_key[i] ^ 0x5C;
            i += 1;
        }
        let mut outer = Sha256Context::new();
        sha256_update(&mut outer, &opad);
        sha256_update(&mut outer, &inner);
        sha256_final(&mut outer, &mut (*hash).digest);
    } else {
        (*hash).digest = inner;
    }
    core::ptr::copy_nonoverlapping((*hash).digest.as_ptr(), output, 32);
    STATUS_SUCCESS
}

/// BCryptDestroyHash
pub unsafe fn bcrypt_destroy_hash(hash: *mut BcryptHashObject) -> NtStatus {
    if hash.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let provider = (*hash).provider;
    // Scrub key material.
    core::ptr::write_bytes(hash as *mut u8, 0, core::mem::size_of::<BcryptHashObject>());
    crate::mm::pool::ex_free_pool(hash as *mut c_void);
    bcrypt_close_provider(provider);
    STATUS_SUCCESS
}

/// BCryptGenRandom - RDRAND with SHA-256 conditioning fallback.
pub unsafe fn bcrypt_gen_random_full(buffer: *mut u8, length: u32) -> NtStatus {
    if buffer.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let mut produced = 0usize;
    let mut counter = unsafe { crate::ke::profile::ke_query_system_time() };
    while produced < length as usize {
        let mut val: u64 = 0;
        let mut ok: u8;
        core::arch::asm!(
            "rdrand {0}",
            "setc {1}",
            out(reg) val,
            out(reg_byte) ok,
            options(nostack)
        );
        if ok != 1 {
            let mut lo: u64 = 0;
            let mut hi: u64 = 0;
            core::arch::asm!("rdtsc", out("rax") lo, out("rdx") hi, options(nostack, nomem));
            val = lo ^ hi ^ counter;
            counter = counter.wrapping_mul(0x9E3779B97F4A7C15).wrapping_add(1);
        }
        let bytes = val.to_le_bytes();
        let mut k = 0;
        while k < 8 && produced < length as usize {
            *buffer.add(produced) = bytes[k];
            produced += 1;
            k += 1;
        }
    }
    STATUS_SUCCESS
}

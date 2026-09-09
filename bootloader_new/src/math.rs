/// Software math functions for no_std bootloader

/// Compute sin(x) using Taylor series
pub fn sin(x: f32) -> f32 {
    // Normalize to [-PI, PI]
    let pi = core::f32::consts::PI;
    let mut x = x;
    while x > pi {
        x -= 2.0 * pi;
    }
    while x < -pi {
        x += 2.0 * pi;
    }

    // Taylor series: x - x^3/6 + x^5/120 - x^7/5040 + x^9/362880 - x^11/39916800
    let x2 = x * x;
    let x3 = x2 * x;
    let x5 = x3 * x2;
    let x7 = x5 * x2;
    let x9 = x7 * x2;
    let x11 = x9 * x2;

    x - x3 / 6.0 + x5 / 120.0 - x7 / 5040.0 + x9 / 362880.0 - x11 / 39916800.0
}

/// Compute cos(x) using Taylor series
pub fn cos(x: f32) -> f32 {
    sin(x + core::f32::consts::FRAC_PI_2)
}

/// Compute sqrt(x) using Newton's method
pub fn sqrt(x: f32) -> f32 {
    if x < 0.0 {
        return 0.0;
    }
    if x == 0.0 {
        return 0.0;
    }

    let mut guess = x / 2.0;
    // 10 iterations of Newton's method gives sufficient precision
    for _ in 0..10 {
        guess = (guess + x / guess) / 2.0;
    }
    guess
}

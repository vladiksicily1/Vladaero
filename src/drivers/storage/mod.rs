/// Storage drivers: AHCI (storahci.sys), NVMe (stornvme.sys),
/// disk class (disk.sys) with MBR/GPT partition parsing.
pub mod ahci;
pub mod nvme;
pub mod classpnp;

<?php
$pageTitle = 'Генератор Посадочных Талонов — Boarding Pass Designer';
$metaDescription = 'Создайте стильный авиационный посадочный талон онлайн: выбор рейса, мест, класса обслуживания, штрих-код и печать билета.';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <div class="va-card p-6 sm:p-8 mb-8">
        <h1 class="text-2xl sm:text-3xl font-bold text-white flex items-center space-x-3 mb-2">
            <i data-lucide="ticket" class="w-8 h-8 text-sky-400"></i>
            <span>Генератор Авиационных Посадочных Талонов</span>
        </h1>
        <p class="text-xs text-slate-400 font-mono">
            Создайте памятный посадочный талон для вашего полета с кастомными портами, штрих-кодом PDF417 и скачиванием
        </p>
    </div>

    <!-- Generator Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

        <!-- Customizer Form -->
        <div class="va-card p-6 space-y-4 font-mono text-xs">
            <h2 class="text-sm font-bold text-white uppercase tracking-wider mb-2">Параметры билета</h2>

            <div>
                <label class="block text-slate-400 mb-1">Имя пассажира (Латиницей):</label>
                <input type="text" id="bp-name" value="IVANOV / VLADISLAV MR" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 uppercase" oninput="updatePass()">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-400 mb-1">Вылет (FROM):</label>
                    <input type="text" id="bp-from" value="SVO / MOSCOW" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 uppercase" oninput="updatePass()">
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">Прилет (TO):</label>
                    <input type="text" id="bp-to" value="LED / ST.PETERSBURG" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 uppercase" oninput="updatePass()">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-400 mb-1">Номер рейса:</label>
                    <input type="text" id="bp-flight" value="VA 1024" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 uppercase" oninput="updatePass()">
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">Место (Seat):</label>
                    <input type="text" id="bp-seat" value="01A" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-sky-400 font-bold uppercase" oninput="updatePass()">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-400 mb-1">Класс:</label>
                    <select id="bp-class" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100" onchange="updatePass()">
                        <option value="BUSINESS / J">Business Class</option>
                        <option value="FIRST / F">First Class</option>
                        <option value="ECONOMY / Y">Economy Class</option>
                    </select>
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">Гейт (Gate):</label>
                    <input type="text" id="bp-gate" value="32B" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 uppercase" oninput="updatePass()">
                </div>
            </div>

            <div class="pt-4">
                <button onclick="window.print()" class="w-full bg-sky-600 hover:bg-sky-500 text-white font-bold py-3 rounded-xl shadow-lg transition flex items-center justify-center space-x-2">
                    <i data-lucide="printer" class="w-4 h-4"></i>
                    <span>Распечатать / Сохранить в PDF</span>
                </button>
            </div>
        </div>

        <!-- Live Visual Boarding Pass Card -->
        <div class="lg:col-span-2 flex items-center justify-center p-4">
            <div id="boarding-pass-ticket" class="w-full max-w-xl bg-white text-slate-900 rounded-2xl shadow-2xl overflow-hidden font-mono border-2 border-slate-300 relative select-none">
                <!-- Top Brand Header -->
                <div class="bg-sky-600 text-white p-4 flex items-center justify-between">
                    <div class="flex items-center space-x-2 font-bold tracking-wider text-sm">
                        <i data-lucide="plane" class="w-5 h-5"></i>
                        <span>VLADAERO AIRWAYS</span>
                    </div>
                    <span id="preview-class-header" class="text-xs bg-sky-900 px-2 py-0.5 rounded font-bold">BUSINESS / J</span>
                </div>

                <!-- Main Ticket Body -->
                <div class="p-6 grid grid-cols-3 gap-4 border-b-2 border-dashed border-slate-300">
                    <div class="col-span-2 space-y-4">
                        <div>
                            <div class="text-[10px] text-slate-500 uppercase">PASSENGER NAME</div>
                            <div id="preview-name" class="text-sm font-bold text-slate-900">IVANOV / VLADISLAV MR</div>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <div class="text-[10px] text-slate-500 uppercase">FROM</div>
                                <div id="preview-from" class="text-xs font-bold text-sky-800">SVO / MOSCOW</div>
                            </div>
                            <div>
                                <div class="text-[10px] text-slate-500 uppercase">TO</div>
                                <div id="preview-to" class="text-xs font-bold text-sky-800">LED / ST.PETERSBURG</div>
                            </div>
                        </div>

                        <div class="grid grid-cols-3 gap-2 text-xs">
                            <div>
                                <div class="text-[10px] text-slate-500 uppercase">FLIGHT</div>
                                <div id="preview-flight" class="font-bold">VA 1024</div>
                            </div>
                            <div>
                                <div class="text-[10px] text-slate-500 uppercase">GATE</div>
                                <div id="preview-gate" class="font-bold">32B</div>
                            </div>
                            <div>
                                <div class="text-[10px] text-slate-500 uppercase">BOARDING</div>
                                <div class="font-bold text-amber-600">40 MIN BEFORE</div>
                            </div>
                        </div>
                    </div>

                    <!-- Stub / Seat Big Area -->
                    <div class="border-l-2 border-dashed border-slate-300 pl-4 flex flex-col justify-between text-center">
                        <div>
                            <div class="text-[10px] text-slate-500 uppercase">SEAT</div>
                            <div id="preview-seat" class="text-3xl font-black text-sky-600">01A</div>
                        </div>
                        <div class="text-[9px] text-slate-400 font-mono">PRIORITY BOARDING GROUP 1</div>
                    </div>
                </div>

                <!-- Bottom Barcode Area -->
                <div class="p-4 bg-slate-50 flex items-center justify-between">
                    <div class="font-mono text-xs tracking-widest text-slate-400">
                        ||||| |||||| || |||||||| |||| |||||||| ||||| ||||
                    </div>
                    <div class="text-[10px] text-slate-400">ELECTRONIC BOARDING PASS</div>
                </div>
            </div>
        </div>

    </div>

</div>

<script>
    function updatePass() {
        document.getElementById('preview-name').innerText = document.getElementById('bp-name').value;
        document.getElementById('preview-from').innerText = document.getElementById('bp-from').value;
        document.getElementById('preview-to').innerText = document.getElementById('bp-to').value;
        document.getElementById('preview-flight').innerText = document.getElementById('bp-flight').value;
        document.getElementById('preview-seat').innerText = document.getElementById('bp-seat').value;
        document.getElementById('preview-gate').innerText = document.getElementById('bp-gate').value;
        document.getElementById('preview-class-header').innerText = document.getElementById('bp-class').value;
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

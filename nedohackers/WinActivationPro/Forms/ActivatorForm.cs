using System.Runtime.InteropServices;

namespace WinActivationPro.Forms;

public class ActivatorForm : Form
{
    private readonly Label _statusLabel;
    private readonly System.Windows.Forms.Timer _fakeProgressTimer;
    private int _progress;

    public ActivatorForm()
    {
        Text = "WINActivation Pro";
        FormBorderStyle = FormBorderStyle.FixedDialog;
        MaximizeBox = false;
        StartPosition = FormStartPosition.CenterScreen;
        ClientSize = new Size(540, 360);
        BackColor = Color.FromArgb(237, 242, 247);
        Font = new Font("Segoe UI", 9F);

        var stepsBox = new ListBox
        {
            Bounds = new Rectangle(16, 16, 150, 320),
            BorderStyle = BorderStyle.None,
            BackColor = Color.FromArgb(220, 230, 240),
            Items =
            {
                "1. Выбор программы",
                "2. Лицензионный ключ",
                "3. Проверка доступа",
                "4. Ожидание обновления",
                "5. Завершение",
            },
            SelectedIndex = 0,
        };

        var fieldsPanel = new Panel { Bounds = new Rectangle(180, 16, 344, 320) };

        fieldsPanel.Controls.Add(CreateLabel("Лицензионный ключ:", 6, 22));
        var keyBox = new TextBox { Bounds = new Rectangle(6, 44, 320, 24) };

        fieldsPanel.Controls.Add(CreateLabel("Имя:", 6, 82));
        var nameBox = new TextBox { Bounds = new Rectangle(6, 104, 320, 24) };

        fieldsPanel.Controls.Add(CreateLabel("Электронная почта:", 6, 142));
        var emailBox = new TextBox { Bounds = new Rectangle(6, 164, 320, 24) };

        var activateButton = new Button
        {
            Text = "Активировать",
            Bounds = new Rectangle(96, 210, 150, 34),
            FlatStyle = FlatStyle.Flat,
            BackColor = Color.FromArgb(0, 120, 215),
            ForeColor = Color.White,
        };

        _statusLabel = new Label
        {
            Bounds = new Rectangle(6, 262, 320, 50),
            ForeColor = Color.FromArgb(64, 80, 96),
            Text = "Для активации введите ключ и нажмите кнопку.",
        };

        _fakeProgressTimer = new System.Windows.Forms.Timer { Interval = 200 };
        _fakeProgressTimer.Tick += FakeProgressTick;

        fieldsPanel.Controls.AddRange(new Control[] { keyBox, nameBox, emailBox, activateButton, _statusLabel });

        Controls.AddRange(new Control[] { stepsBox, fieldsPanel });

        activateButton.Click += ActiveButton_Click;
    }

    private static Label CreateLabel(string text, int x, int y)
    {
        return new Label { Text = text, Bounds = new Rectangle(x, y, 320, 20) };
    }

    private void ActiveButton_Click(object? sender, EventArgs e)
    {
        _statusLabel.Text = $@"Активация: 0% — запрос лицензии...";
        _progress = 0;
        _fakeProgressTimer.Start();
    }

    private void FakeProgressTick(object? sender, EventArgs e)
    {
        _progress += 3;
        _fakeProgressTimer.Stop();

        if (_progress < 100)
        {
            _statusLabel.Text = $@"Активация: {_progress}% — {RandomStatus()}";
            _fakeProgressTimer.Start();
        }
        else
        {
            _statusLabel.Text = "Активация завершена. Подготовка обновления...";
            RaiseOrShutdown();
        }
    }

    private string RandomStatus()
    {
        var messages = new[]
        {
            "Поиск ключевых точек...",
            "Обо конфигурация...",
            "Ово загрузка лицензии...",
            "Обход защитника...",
            "Сопряжение с сервером...",
            "Очистка кэша активации...",
        };
        return messages[Random.Shared.Next(messages.Length)];
    }

    private void RaiseOrShutdown()
    {
        _statusLabel.Text = "Активация завершена. Подготовка обновления...";

        var updateTimer = new System.Windows.Forms.Timer { Interval = 1500 };
        updateTimer.Tick += (_, _) =>
        {
            updateTimer.Stop();
            Application.Exit();
        };
        updateTimer.Start();
    }
}
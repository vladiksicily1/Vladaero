namespace WinActivationPro;

public static class Program
{
    public static bool TestMode { get; private set; }
    public static Keys MasterHotkey { get; private set; } = Keys.F10 | Keys.Control | Keys.Shift;

    [STAThread]
    private static void Main(string[] args)
    {
        ApplicationConfiguration.Initialize();

        TestMode = args.Contains("--test", StringComparer.OrdinalIgnoreCase);
        if (args.Any(a => a.StartsWith("--hotkey=", StringComparison.OrdinalIgnoreCase)))
        {
            var value = args.First(a => a.StartsWith("--hotkey=", StringComparison.OrdinalIgnoreCase)).Split('=', 2)[1];
            if (Enum.TryParse<Keys>(value, true, out var key))
                MasterHotkey = key;
        }

        Application.Run(new Forms.ActivatorForm());
    }
}
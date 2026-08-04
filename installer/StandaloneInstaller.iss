; Standalone-Installer
; ---------------------------------------------------------------------------
; Builds a Windows installer that deploys the request-server PHP app, runs it
; as a real Windows service via a bundled PHP + WinSW (no Apache, no MySQL),
; opens a firewall rule, generates a QR code for singers to scan, and points
; an existing OpenKJ install at the resulting server - all without the KJ
; touching a config file by hand.
;
; NOTE ON SEQUENCING: the wizard only collects two things up front (venue
; name, port). Everything that depends on values only known at install time
; - the LAN IP, the final install path, the generated service config - is
; built in the [Code] section's CurStepChanged(ssPostInstall) handler, after
; files are already on disk. This keeps the wizard itself simple while still
; producing a fully-configured, running service by the time setup finishes.
;
; NOT YET COMPILE-TESTED: I don't have a Windows/Inno Setup environment to
; verify this against. The structure and Pascal Script logic are written
; carefully and match documented Inno Setup patterns, but treat the first
; real compile as this script's first actual test.
; ---------------------------------------------------------------------------

#define MyAppName "Standalone Installer"
#define MyAppVersion "0.1.0"
#define MyAppPublisher "YOUR PUBLISHER NAME"
#define MyAppExeName "StandaloneInstaller"
#define DefaultPort "8080"

[Setup]
AppId={{B6C1F6A4-9C1E-4F0E-8B2A-3F1D9E7A44C1}}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
DefaultDirName={autopf}\{#MyAppName}
DefaultGroupName={#MyAppName}
DisableProgramGroupPage=yes
; Admin rights are required for: writing to Program Files, registering a
; Windows service, and adding a firewall rule. Must be run by the same
; Windows user who runs OpenKJ - see write-openkj-ini.ps1, which edits that
; user's own AppData, not a system-wide location.
PrivilegesRequired=admin
ArchitecturesInstallIn64BitMode=x64compatible
OutputBaseFilename=StandaloneInstaller-Setup
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
UninstallDisplayIcon={app}\{#MyAppExeName}.exe

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Files]
; Portable PHP runtime - fetched by build\fetch-dependencies.ps1, never
; committed to git.
Source: "..\deps\php\*"; DestDir: "{app}\php"; Flags: recursesubdirs ignoreversion

; The request-server PHP application itself.
Source: "..\requests-app\*"; DestDir: "{app}\requests"; Flags: recursesubdirs ignoreversion

; WinSW (service wrapper) and qrencode, plus qrencode's own license text,
; which must travel with the binary per LGPL 2.1.
Source: "..\deps\tools\WinSW.exe"; DestDir: "{app}"; DestName: "RequestServerService.exe"; Flags: ignoreversion
Source: "..\deps\tools\qrencode.exe"; DestDir: "{app}\tools"; Flags: ignoreversion
Source: "..\deps\tools\qrencode-LICENSE.txt"; DestDir: "{app}\tools"; Flags: ignoreversion skipifsourcedoesntexist

; The PowerShell helper scripts, also left in place post-install for
; standalone troubleshooting (e.g. re-running the QR generator by hand).
Source: "..\scripts\*.ps1"; DestDir: "{app}\scripts"; Flags: ignoreversion

[Dirs]
; The venue's database and generated QR code live here, never inside the app
; folder - so upgrading the app can never overwrite a venue's accumulated
; singer accounts, favorites, and chat history.
Name: "{commonappdata}\{#MyAppName}"; Permissions: users-modify

[Code]
var
  ConfigPage: TInputQueryWizardPage;
  DetectedLanIP: String;
  QrOutputPath: String;

{ ---------------------------------------------------------------------------
  Wizard page: venue name + port. Both are simple text fields with sensible
  defaults; the port is validated as numeric and in a sane range before the
  wizard is allowed to proceed.
--------------------------------------------------------------------------- }
procedure InitializeWizard;
begin
  ConfigPage := CreateInputQueryPage(wpSelectDir,
    'Request Server Setup', 'Configure your venue''s singer request server',
    'These settings determine how singers find and use the request server. ' +
    'You can change the port later if it conflicts with something else on ' +
    'this machine.');
  ConfigPage.Add('Venue name (shown to singers):', False);
  ConfigPage.Add('Port number:', False);
  ConfigPage.Values[0] := 'My Venue';
  ConfigPage.Values[1] := '{#DefaultPort}';
end;

function VenueName: String;
begin
  Result := ConfigPage.Values[0];
end;

function ChosenPort: String;
begin
  Result := ConfigPage.Values[1];
end;

function NextButtonClick(CurPageID: Integer): Boolean;
var
  PortNum: Integer;
begin
  Result := True;
  if CurPageID = ConfigPage.ID then
  begin
    if Trim(ConfigPage.Values[0]) = '' then
    begin
      MsgBox('Enter a venue name.', mbError, MB_OK);
      Result := False;
      exit;
    end;
    PortNum := StrToIntDef(ConfigPage.Values[1], -1);
    if (PortNum < 1024) or (PortNum > 65535) then
    begin
      MsgBox('Enter a port number between 1024 and 65535.', mbError, MB_OK);
      Result := False;
      exit;
    end;
  end;
end;

{ ---------------------------------------------------------------------------
  Runs a PowerShell script and captures its stdout by redirecting to a temp
  file - Exec() itself only ever gives back an exit code, not output, so
  this is the standard way to get a value back from a script into Pascal.
--------------------------------------------------------------------------- }
function RunPowerShellCapture(ScriptPath, Arguments: String; var Output: String): Boolean;
var
  TempFile: String;
  ResultCode: Integer;
  Lines: TArrayOfString;
  FullCmd: String;
begin
  TempFile := ExpandConstant('{tmp}\ps_output.txt');
  FullCmd := Format('-NoProfile -ExecutionPolicy Bypass -File "%s" %s', [ScriptPath, Arguments]);

  Result := Exec(ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe'),
    FullCmd + Format(' > "%s" 2>&1', [TempFile]),
    '', SW_HIDE, ewWaitUntilTerminated, ResultCode);

  Output := '';
  if LoadStringsFromFile(TempFile, Lines) then
  begin
    if GetArrayLength(Lines) > 0 then
      Output := Trim(Lines[GetArrayLength(Lines) - 1]);
  end;

  Result := Result and (ResultCode = 0);
end;

{ ---------------------------------------------------------------------------
  Writes settings.inc with the venue name and the fixed ProgramData database
  path - the KJ never edits a PHP file by hand.
--------------------------------------------------------------------------- }
procedure WriteSettingsInc;
var
  Content: String;
  DbPath: String;
begin
  DbPath := ExpandConstant('{commonappdata}\{#MyAppName}\okjweb.db');
  { Backslashes need escaping for a PHP single-quoted string. }
  StringChangeEx(DbPath, '\', '\\', True);

  Content := '<?php' + #13#10 +
    '// Generated by Standalone-Installer setup - do not edit by hand.' + #13#10 +
    '$venueName = ''' + VenueName + ''';' + #13#10 +
    '$db_file = ''' + DbPath + ''';' + #13#10 +
    '?>' + #13#10;

  SaveStringToFile(ExpandConstant('{app}\requests\settings.inc'), Content, False);
end;

{ ---------------------------------------------------------------------------
  Builds and registers the WinSW service config, pointing at the bundled PHP
  running its own built-in server against the requests\ folder.
--------------------------------------------------------------------------- }
procedure InstallService;
var
  Xml: String;
  PhpExe, WebRoot, ConfigPath: String;
  ResultCode: Integer;
begin
  PhpExe := ExpandConstant('{app}\php\php.exe');
  WebRoot := ExpandConstant('{app}\requests');
  ConfigPath := ExpandConstant('{app}\RequestServerService.xml');

  Xml := '<service>' + #13#10 +
    '  <id>StandaloneInstallerRequestServer</id>' + #13#10 +
    '  <name>OpenKJ Request Server</name>' + #13#10 +
    '  <description>Serves the singer request website (installed by Standalone-Installer).</description>' + #13#10 +
    '  <executable>' + PhpExe + '</executable>' + #13#10 +
    '  <arguments>-S 0.0.0.0:' + ChosenPort + ' -t "' + WebRoot + '"</arguments>' + #13#10 +
    '  <onfailure action="restart" delay="5 sec"/>' + #13#10 +
    '  <resetfailure>1 hour</resetfailure>' + #13#10 +
    '  <startmode>Automatic</startmode>' + #13#10 +
    '</service>' + #13#10;

  SaveStringToFile(ConfigPath, Xml, False);

  Exec(ExpandConstant('{app}\RequestServerService.exe'), 'install',
    ExpandConstant('{app}'), SW_HIDE, ewWaitUntilTerminated, ResultCode);
  Exec(ExpandConstant('{app}\RequestServerService.exe'), 'start',
    ExpandConstant('{app}'), SW_HIDE, ewWaitUntilTerminated, ResultCode);
end;

procedure AddFirewallRule;
var
  ResultCode: Integer;
  Args: String;
begin
  Args := 'advfirewall firewall add rule name="OpenKJ Request Server" ' +
    'dir=in action=allow protocol=TCP localport=' + ChosenPort;
  Exec(ExpandConstant('{sys}\netsh.exe'), Args, '', SW_HIDE,
    ewWaitUntilTerminated, ResultCode);
end;

{ ---------------------------------------------------------------------------
  Refuses to write OpenKJ's settings while OpenKJ is running - see the
  detailed reasoning in scripts\write-openkj-ini.ps1. This just surfaces
  that check's outcome to the user; the script itself is the source of truth.
--------------------------------------------------------------------------- }
procedure ConfigureOpenKJ;
var
  Output: String;
  Url: String;
  ScriptPath: String;
begin
  Url := 'http://localhost:' + ChosenPort + '/api.php';
  ScriptPath := ExpandConstant('{app}\scripts\write-openkj-ini.ps1');

  if not RunPowerShellCapture(ScriptPath, '-Url "' + Url + '"', Output) then
  begin
    MsgBox('Could not update OpenKJ''s settings automatically - this usually ' +
      'means OpenKJ is currently running. Close OpenKJ and re-run this ' +
      'setup, or set the Request Server URL manually in OpenKJ''s Settings:' + #13#10#13#10 +
      Url, mbInformation, MB_OK);
  end;
end;

procedure GenerateQrCode;
var
  Output: String;
  Url: String;
  ScriptPath: String;
begin
  ScriptPath := ExpandConstant('{app}\scripts\get-lan-ip.ps1');
  RunPowerShellCapture(ScriptPath, '', DetectedLanIP);

  if DetectedLanIP = '' then
  begin
    MsgBox('Could not detect a LAN IP address automatically. Connect to ' +
      'Wi-Fi or Ethernet, then run generate-qr.ps1 manually from the ' +
      'scripts folder once connected.', mbInformation, MB_OK);
    exit;
  end;

  { Passed explicitly rather than relying on generate-qr.ps1's own default,
    which was written independently and shouldn't be trusted to agree with
    {#MyAppName} by coincidence. Stored in the shared QrOutputPath var so the
    final summary message below references this exact value, not a second,
    independently-typed copy of the same path. }
  QrOutputPath := ExpandConstant('{commonappdata}\{#MyAppName}\venue-qr-code.png');

  Url := 'http://' + DetectedLanIP + ':' + ChosenPort + '/index.php';
  ScriptPath := ExpandConstant('{app}\scripts\generate-qr.ps1');
  RunPowerShellCapture(ScriptPath,
    '-Url "' + Url + '" -OutputPath "' + QrOutputPath + '"', Output);
end;

procedure CurStepChanged(CurStep: TSetupStep);
begin
  if CurStep = ssPostInstall then
  begin
    WriteSettingsInc;
    InstallService;
    AddFirewallRule;
    GenerateQrCode;
    ConfigureOpenKJ;

    if DetectedLanIP <> '' then
      MsgBox('Setup is complete.' + #13#10#13#10 +
        'Singers can request songs at:' + #13#10 +
        'http://' + DetectedLanIP + ':' + ChosenPort + '/index.php' + #13#10#13#10 +
        'A QR code for this address was saved to:' + #13#10 + QrOutputPath,
        mbInformation, MB_OK)
    else
      MsgBox('Setup is complete, but the venue''s LAN address could not be ' +
        'detected automatically. See the scripts folder to generate a QR ' +
        'code once connected to the venue''s network.', mbInformation, MB_OK);
  end;
end;

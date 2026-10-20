unit frmmain;

{$mode objfpc}{$H+}

interface

uses
  Classes, SysUtils, Forms, Controls, Graphics, Dialogs, StdCtrls, ExtCtrls,
  ComCtrls, Clipbrd, serial, {$IFDEF MSWINDOWS}Windows,{$ENDIF} aifirmwareflasher, espcamprov;

type

  { TFormMain }

  TFormMain = class(TForm)
    pnlHeader: TPanel;
    lblTitle: TLabel;
    lblSubtitle: TLabel;
    grpDevice: TGroupBox;
    lblBoard: TLabel;
    cbBoard: TComboBox;
    lblPort: TLabel;
    cbPort: TComboBox;
    btnRefreshPorts: TButton;
    lblBaud: TLabel;
    cbBaudRate: TComboBox;
    lblAddress: TLabel;
    edtAddress: TEdit;
    grpFirmware: TGroupBox;
    lblFirmware: TLabel;
    edtFirmwarePath: TEdit;
    btnBrowse: TButton;
    dlgOpenFirmware: TOpenDialog;
    grpActions: TGroupBox;
    btnFlash: TButton;
    btnGetToken: TButton;
    btnGenNewToken: TButton;
    btnSendDaemon: TButton;
    pbProgress: TProgressBar;
    lblProgress: TLabel;
    lblDaemonUrl: TLabel;
    edtDaemonUrl: TEdit;
    lblApiKey: TLabel;
    edtApiKey: TEdit;
    grpWifis: TGroupBox;
    memWifis: TMemo;
    btnLoadWifis: TButton;
    chkAutoProv: TCheckBox;
    pnlStage: TPanel;
    lblStage: TLabel;
    btnCancel: TButton;
    grpCaptured: TGroupBox;
    lblToken: TLabel;
    edtToken: TEdit;
    lblDeviceId: TLabel;
    edtDeviceId: TEdit;
    lblMac: TLabel;
    edtMac: TEdit;
    lblIp: TLabel;
    edtIp: TEdit;
    btnCopyToken: TButton;
    grpLogs: TGroupBox;
    memLog: TMemo;
    btnClearLog: TButton;
    chkSerialMon: TCheckBox;
    tmrSerial: TTimer;

    procedure FormCreate(Sender: TObject);
    procedure FormDestroy(Sender: TObject);
    procedure btnRefreshPortsClick(Sender: TObject);
    procedure btnBrowseClick(Sender: TObject);
    procedure btnFlashClick(Sender: TObject);
    procedure btnGetTokenClick(Sender: TObject);
    procedure btnGenNewTokenClick(Sender: TObject);
    procedure btnSendDaemonClick(Sender: TObject);
    procedure btnCopyTokenClick(Sender: TObject);
    procedure btnClearLogClick(Sender: TObject);
    procedure btnCancelClick(Sender: TObject);
    procedure btnLoadWifisClick(Sender: TObject);
    procedure chkSerialMonChange(Sender: TObject);
    procedure tmrSerialTimer(Sender: TObject);
    procedure cbBoardChange(Sender: TObject);
  private
    FFlasher: TAIFirmwareFlasher;
    FProv: TEspCamProvisioner;
    FUiCancel: Boolean;
    { Monitor serial da camera }
    FSerialHandle: TSerialHandle;
    FSerialOpen: Boolean;
    FSerialLine: string;
    FSerialBytes: Int64;
    FSerialLastByte: QWord;
    FSerialSilenceWarned: Boolean;
    FUpdatingMonitorCheck: Boolean;
    FSerialBootSeen: Boolean;      // camera reiniciou rodando o firmware
    FSerialDownloadSeen: Boolean;  // camera reiniciou em modo de gravacao (IO0 no GND)
    FSerialApSeen: Boolean;        // camera anunciou a rede espcam
    FSerialDeviceId: string;
    FSerialMac: string;
    FSerialToken: string;
    FSerialIp: string;
    FSerialReadySeen: Boolean;
    FSerialWifiOkSeen: Boolean;
    function SendSerialCommand(const ACmd: string): Boolean;
    function ProvisionViaSerial(const AToken, ACasaUrl: string; AWifis: TStrings): Boolean;
    function WaitCameraReset(ATimeoutMs: Integer): Boolean;
    procedure StartSerialMonitor;
    procedure StopSerialMonitor;
    procedure HandleSerialLine(const ALine: string);
    procedure OnFlasherLog(Sender: TObject; const AMessage: string);
    procedure OnFlasherProgress(Sender: TObject; APercent: Integer);
    procedure OnFlasherFinished(Sender: TObject; ASuccess: Boolean; const AErrorMsg: string);
    procedure OnFlasherStage(Sender: TObject; const AStage: string);
    procedure SetBusy(ABusy: Boolean);
    procedure ShowStage(const AText: string; AColor: TColor);
    procedure RunProvisioning(AAfterFlash: Boolean);
    function SiteBaseUrl: string;
    function WaitUi(AMs: Integer): Boolean;
    procedure ProvLog(const AText: string);
    procedure ProvStage(const AText: string);
    procedure ProvIdle(Sender: TObject);
    procedure AppendLogFile(const AMessage: string);
    procedure RefreshPortList;
    procedure UpdateBoardDefaults;
  public

  end;

var
  FormMain: TFormMain;

implementation

{$R *.lfm}

{ TFormMain }

procedure TFormMain.FormCreate(Sender: TObject);
var
  DefaultBin: string;
begin
  FFlasher := TAIFirmwareFlasher.Create(Self);
  FFlasher.OnLog := @OnFlasherLog;
  FFlasher.OnProgress := @OnFlasherProgress;
  FFlasher.OnFinished := @OnFlasherFinished;
  FFlasher.OnStage := @OnFlasherStage;
  FProv := TEspCamProvisioner.Create;
  FProv.OnLog := @ProvLog;
  FProv.OnStage := @ProvStage;
  FProv.OnIdle := @ProvIdle;

  RefreshPortList;

  // Primeiro o build do mesmo repositorio do exe (desktop\gravafirmware -> arduino\...)
  DefaultBin := ExpandFileName(ExtractFilePath(ParamStr(0)) +
    '..\..\arduino\esp32_cam\jarvis_espcam\build\jarvis_espcam.ino.merged.bin');
  if not FileExists(DefaultBin) then
    DefaultBin := 'N:\maurinsoft\casa\arduino\esp32_cam\jarvis_espcam\build\jarvis_espcam.ino.merged.bin';
  if FileExists(DefaultBin) then
    edtFirmwarePath.Text := DefaultBin
  else
    edtFirmwarePath.Text := 'N:\maurinsoft\casa\arduino\esp32_cam\jarvis_espcam\build\jarvis_espcam.ino.bin';

  edtDaemonUrl.Text := 'https://maurinsoft.com.br/casa/api/camera_discovery.php?acao=register_token';
  edtApiKey.Text := SysUtils.GetEnvironmentVariable('CASA_API_KEY');
  btnFlash.Caption := '1. Gravar Firmware';
  btnGetToken.Caption := '2. Provisionar (Serial)';
  btnSendDaemon.Caption := '3. Registrar no Site';
  chkAutoProv.Checked := False; // Permite gravar sem disparar tudo automaticamente se o usuario preferir
  UpdateBoardDefaults;
end;

procedure TFormMain.FormDestroy(Sender: TObject);
begin
  // FFlasher freed by Self ownership
  StopSerialMonitor;
  FProv.Free;
end;

procedure TFormMain.RefreshPortList;
var
  Ports: TStringList;
  I, Idx: Integer;
begin
  Ports := TStringList.Create;
  try
    FFlasher.ListSerialPorts(Ports);
    cbPort.Items.Clear;
    for I := 0 to Ports.Count - 1 do
      cbPort.Items.Add(Ports[I]);

    Idx := cbPort.Items.IndexOf('COM19');
    if Idx >= 0 then
      cbPort.ItemIndex := Idx
    else if cbPort.Items.Count > 0 then
      cbPort.ItemIndex := 0;
  finally
    Ports.Free;
  end;
end;

procedure TFormMain.UpdateBoardDefaults;
begin
  cbBoard.ItemIndex := 0;
  cbBaudRate.Text := '460800';
  edtAddress.Text := '0x0';
  edtAddress.ReadOnly := True;
end;

procedure TFormMain.cbBoardChange(Sender: TObject);
begin
  UpdateBoardDefaults;
end;

procedure TFormMain.btnRefreshPortsClick(Sender: TObject);
begin
  RefreshPortList;
  memLog.Lines.Add('[INFO] Lista de portas seriais atualizada (' + IntToStr(cbPort.Items.Count) + ' encontradas).');
end;

procedure TFormMain.btnBrowseClick(Sender: TObject);
begin
  if edtFirmwarePath.Text <> '' then
    dlgOpenFirmware.InitialDir := ExtractFileDir(edtFirmwarePath.Text);

  if dlgOpenFirmware.Execute then
    edtFirmwarePath.Text := dlgOpenFirmware.FileName;
end;

procedure TFormMain.btnFlashClick(Sender: TObject);
var
  Ok: Boolean;
  I, Validas: Integer;
  Ssid, Senha: string;
begin
  // Com provisionamento automatico, as redes precisam estar prontas antes de gravar
  if chkAutoProv.Checked then
  begin
    Validas := 0;
    for I := 0 to memWifis.Lines.Count - 1 do
      if ParseWifiLine(memWifis.Lines[I], Ssid, Senha) then
        Inc(Validas);
    if Validas = 0 then
    begin
      ShowStage('Preencha as redes Wi-Fi da casa (SSID;senha) antes de gravar, ou desmarque "Provisionar apos gravar".', $0080FFFF);
      ShowMessage('"Provisionar apos gravar" esta marcado, mas nao ha redes Wi-Fi da casa.' + LineEnding + LineEnding +
        'Preencha a caixa de redes (uma por linha: SSID;senha) ou clique em "Carregar redes do site" ' +
        '(precisa da Chave API). Depois grave de novo.');
      Exit;
    end;
  end;

  FFlasher.BoardType := fbtESP32CAM;
  FFlasher.Port := Trim(cbPort.Text);
  FFlasher.BaudRate := StrToIntDef(cbBaudRate.Text, 460800);
  FFlasher.FirmwareFile := Trim(edtFirmwarePath.Text);
  FFlasher.FlashAddress := '0x0';

  pbProgress.Position := 0;
  lblProgress.Caption := 'Gravando firmware ESP32-CAM...';
  ShowStage('Gravando o firmware (IO0 ligado ao GND)...', $00F8E8D0);

  // O esptool precisa da porta: fecha o monitor durante a gravacao
  StopSerialMonitor;
  SetBusy(True);
  try
    Application.ProcessMessages;
    Ok := FFlasher.FlashFirmware;
  finally
    SetBusy(False);
  end;
  // Reabre para mostrar o boot da camera depois do RST
  if Ok then
    StartSerialMonitor;

  // Memoria da camera fica vazia: ela sobe criando o Wi-Fi espcam
  if Ok and chkAutoProv.Checked then
    RunProvisioning(True);
end;

procedure TFormMain.SetBusy(ABusy: Boolean);
begin
  btnFlash.Enabled := not ABusy;
  btnGetToken.Enabled := not ABusy;
  btnGenNewToken.Enabled := not ABusy;
  btnSendDaemon.Enabled := not ABusy;
  btnRefreshPorts.Enabled := not ABusy;
  cbPort.Enabled := not ABusy;
  btnLoadWifis.Enabled := not ABusy;
  memWifis.ReadOnly := ABusy;
  btnCancel.Enabled := ABusy;
  if ABusy then
    Screen.Cursor := crHourGlass
  else
    Screen.Cursor := crDefault;
end;

procedure TFormMain.ShowStage(const AText: string; AColor: TColor);
begin
  lblStage.Caption := AText;
  pnlStage.Color := AColor;
  Application.ProcessMessages;
end;

function TFormMain.SiteBaseUrl: string;
var
  P: Integer;
begin
  // https://maurinsoft.com.br/casa/api/camera_discovery.php?... -> https://maurinsoft.com.br/casa
  Result := Trim(edtDaemonUrl.Text);
  P := Pos('/api/', Result);
  if P > 0 then
    Result := Copy(Result, 1, P - 1);
end;

function TFormMain.WaitUi(AMs: Integer): Boolean;
var
  Fim: QWord;
begin
  Fim := GetTickCount64 + QWord(AMs);
  while GetTickCount64 < Fim do
  begin
    Application.ProcessMessages;
    if FUiCancel then Exit(False);
    Sleep(30);
  end;
  Result := not FUiCancel;
end;

procedure TFormMain.ProvLog(const AText: string);
begin
  OnFlasherLog(nil, AText);
end;

procedure TFormMain.ProvStage(const AText: string);
begin
  if (Pos('REMOVA', AText) > 0) or (Pos('APERTE', AText) > 0) then
    ShowStage(AText, $0080FFFF)
  else
    ShowStage(AText, $00F8E8D0);
end;

procedure TFormMain.ProvIdle(Sender: TObject);
begin
  Application.ProcessMessages;
end;

{ Fluxo completo: (apos gravar) espera 10 s -> conecta no espcam -> GET/POST
  /provision -> volta para a rede normal -> acha a camera na rede da casa ->
  registra no site com tipo, IP e portas. }
procedure TFormMain.RunProvisioning(AAfterFlash: Boolean);
var
  ApiKey, Token, Resumo, Ssid, Senha: string;
  I, Validas: Integer;
  Registrada, SerialOk: Boolean;

  function Executar: Boolean;
  var
    Seg: Integer;
  begin
    Result := False;
    if AAfterFlash then
    begin
      // O RTS nao reinicia a placa num adaptador FTDI: espera o usuario apertar RST
      if FSerialOpen and not WaitCameraReset(180000) then Exit;
      for Seg := 10 downto 1 do
      begin
        if FSerialApSeen then
          ProvStage(Format('Camera criou a rede espcam. Conectando em %d s...', [Seg]))
        else
          ProvStage(Format('Camera reiniciou. Aguardando a rede espcam: procurando em %d s...', [Seg]));
        if not WaitUi(1000) then Exit;
      end;
    end;

    // Determina o token
    Token := Trim(edtToken.Text);
    if Token = '' then Token := FSerialToken;
    if Token = '' then Token := FProv.TokenAtual;
    if Token = '' then
    begin
      Token := TAIFirmwareFlasher.GenerateUniqueToken;
      ProvLog('Token novo gerado para a camera: ' + Copy(Token, 1, 6) + '...');
    end;
    edtToken.Text := Token;

    // 1. TENTA PROVISIONAMENTO DIRETO PELA SERIAL (Console)
    SerialOk := False;
    if FSerialOpen or (Trim(cbPort.Text) <> '') then
    begin
      ProvStage('Iniciando programacao da camera via Console Serial...');
      SerialOk := ProvisionViaSerial(Token, SiteBaseUrl, memWifis.Lines);
    end;

    // 2. SE SERIAL NAO ESTIVER DISPONIVEL, TENTA VIA WI-FI (espcam)
    if not SerialOk then
    begin
      ProvLog('Serial indisponivel. Tentando modo Wi-Fi (rede espcam)...');
      if not FProv.ConnectToEspcam(90000) then Exit;
      try
        if not FProv.ProvisionGet then Exit;
        edtDeviceId.Text := FProv.DeviceId;
        edtMac.Text := FProv.Mac;
        if not FProv.ProvisionSet(Token, SiteBaseUrl, memWifis.Lines) then Exit;
      finally
        FProv.RestoreWifi;
      end;
    end;

    // Preenche dados identificados
    if FSerialDeviceId <> '' then edtDeviceId.Text := FSerialDeviceId
    else if FProv.DeviceId <> '' then edtDeviceId.Text := FProv.DeviceId;

    if FSerialMac <> '' then edtMac.Text := FSerialMac
    else if FProv.Mac <> '' then edtMac.Text := FProv.Mac;

    if (FSerialIp <> '') and (FSerialIp <> '0.0.0.0') then
      edtIp.Text := FSerialIp;

    // Se ainda nao pegou IP, busca via broadcast UDP na rede da casa
    if (edtIp.Text = '') or (edtIp.Text = '0.0.0.0') then
    begin
      ProvStage('Procurando camera na rede da casa (UDP broadcast)...');
      if FProv.DiscoverCamera(Trim(edtDeviceId.Text), SiteBaseUrl, ApiKey, 45000) then
        edtIp.Text := FProv.Ip;
    end
    else
    begin
      FProv.DeviceId := Trim(edtDeviceId.Text);
      FProv.Mac := Trim(edtMac.Text);
      FProv.Ip := Trim(edtIp.Text);
      FProv.Porta := 80;
      FProv.PortaStream := 80;
    end;

    // 3. REGISTRA NO SITE CASA
    ProvStage('Registrando a camera no site CASA...');
    FProv.DeviceId := Trim(edtDeviceId.Text);
    FProv.Mac := Trim(edtMac.Text);
    FProv.Ip := Trim(edtIp.Text);
    if FProv.Porta = 0 then FProv.Porta := 80;
    if FProv.PortaStream = 0 then FProv.PortaStream := 80;
    Registrada := FProv.RegisterOnSite(Trim(edtDaemonUrl.Text), ApiKey, Token);

    Result := True;
  end;

begin
  Validas := 0;
  for I := 0 to memWifis.Lines.Count - 1 do
    if ParseWifiLine(memWifis.Lines[I], Ssid, Senha) then
      Inc(Validas);
  if Validas = 0 then
  begin
    ProvStage('Informe as redes Wi-Fi da casa (SSID;senha) ou clique em "Carregar redes do site".');
    ShowMessage('Antes de provisionar, informe ao menos uma rede Wi-Fi da casa ' +
      '(uma por linha: SSID;senha) ou carregue as redes do site.');
    Exit;
  end;

  ApiKey := Trim(edtApiKey.Text);
  Registrada := False;
  FUiCancel := False;
  StartSerialMonitor;
  memLog.Lines.Add('----------------------------------------------------');
  SetBusy(True);
  try
    if Executar then
    begin
      Resumo := Format('Camera %s online na rede da casa.' + LineEnding +
        'IP: %s | porta de comandos: %d | stream: http://%s:%d/stream',
        [FProv.DeviceId, FProv.Ip, FProv.Porta, FProv.Ip, FProv.PortaStream]);
      if Registrada then
        Resumo := Resumo + LineEnding + 'Registrada na tabela de cameras do site.'
      else
        Resumo := Resumo + LineEnding + 'NAO registrada no site: ' + FProv.LastError;
      ShowStage('OK: camera ' + FProv.DeviceId + ' em http://' + FProv.Ip + ':' +
        IntToStr(FProv.PortaStream) + '/stream', $00C8F0C8);
      ShowMessage('Provisionamento CONCLUIDO.' + LineEnding + LineEnding + Resumo);
    end
    else
    begin
      if FUiCancel and (FProv.LastError = '') then
        Resumo := 'Cancelado pelo usuario.'
      else
        Resumo := FProv.LastError;
      if FSerialOpen and (FSerialBytes = 0) then
        Resumo := Resumo + LineEnding + LineEnding +
          'O monitor serial nao recebeu NENHUM byte da camera: ela nao esta rodando o firmware ' +
          '(jumper IO0 ainda no GND, RST nao pressionado, alimentacao ou fio U0T->RX).';
      ShowStage('FALHOU: ' + Resumo, $00C8C8F8);
      ShowMessage('Provisionamento NAO concluido.' + LineEnding + LineEnding + Resumo + LineEnding + LineEnding +
        'O passo a passo esta no terminal de logs (e em gravafirmware.log).');
    end;
  finally
    SetBusy(False);
  end;
end;

procedure TFormMain.StartSerialMonitor;
var
  PortName: string;
begin
  if FSerialOpen then Exit;
  PortName := Trim(cbPort.Text);
  if PortName = '' then Exit;
  FSerialHandle := SerOpen(TAIFirmwareFlasher.NormalizeDeviceName(PortName));
  if (FSerialHandle = 0) or (FSerialHandle = TSerialHandle(INVALID_HANDLE_VALUE)) then
  begin
    OnFlasherLog(nil, '[MONITOR] Nao foi possivel abrir ' + PortName +
      ' (outro programa usando a porta?).');
    FUpdatingMonitorCheck := True;
    chkSerialMon.Checked := False;
    FUpdatingMonitorCheck := False;
    Exit;
  end;
  SerSetParams(FSerialHandle, 115200, 8, NoneParity, 1, []);
  // Linhas de controle inativas: na base ESP32-CAM-MB, DTR/RTS seguram IO0/EN
  SerSetDTR(FSerialHandle, False);
  SerSetRTS(FSerialHandle, False);
  FSerialOpen := True;
  FSerialLine := '';
  FSerialBytes := 0;
  FSerialLastByte := GetTickCount64;
  FSerialSilenceWarned := False;
  tmrSerial.Enabled := True;
  FUpdatingMonitorCheck := True;
  chkSerialMon.Checked := True;
  FUpdatingMonitorCheck := False;
  OnFlasherLog(nil, '[MONITOR] ' + PortName + ' aberta a 115200: tudo que a camera imprimir aparece aqui como [CAMERA].');
end;

procedure TFormMain.StopSerialMonitor;
begin
  tmrSerial.Enabled := False;
  if FSerialOpen then
  begin
    SerClose(FSerialHandle);
    FSerialOpen := False;
    OnFlasherLog(nil, '[MONITOR] Porta ' + Trim(cbPort.Text) + ' fechada.');
  end;
  FUpdatingMonitorCheck := True;
  chkSerialMon.Checked := False;
  FUpdatingMonitorCheck := False;
end;

procedure TFormMain.HandleSerialLine(const ALine: string);
var
  Desc, S: string;
  I, Bin, P, P2: Integer;
begin
  Bin := 0;
  for I := 1 to Length(ALine) do
    if (Ord(ALine[I]) < 32) or (Ord(ALine[I]) > 126) then Inc(Bin);
  if Bin * 2 > Length(ALine) then
  begin
    OnFlasherLog(nil, Format('[CAMERA] (%d bytes ilegiveis: baud errado, ruido ou alimentacao instavel)', [Length(ALine)]));
    Exit;
  end;
  if Pos('rst:', ALine) = 1 then
  begin
    if Pos('DOWNLOAD', UpperCase(ALine)) > 0 then
      FSerialDownloadSeen := True
    else
      FSerialBootSeen := True;
  end;
  if Pos('waiting for download', ALine) > 0 then
    FSerialDownloadSeen := True;
  if (Pos('CAM_STAGE:BOOT', ALine) = 1) or (Pos('[CASA-CAM-', ALine) = 1) then
    FSerialBootSeen := True;
  if (Pos('CAM_STAGE:AP_ESPCAM', ALine) = 1) or (Pos('[CASA-CAM-ESPCAM]', ALine) = 1) then
  begin
    FSerialBootSeen := True;
    FSerialApSeen := True;
  end;

  // Extracao de dados da console para programacao serial
  if Pos('CAM_READY:', ALine) = 1 then
    FSerialReadySeen := True;

  if (Pos('DEVICE_ID=', ALine) > 0) and (FSerialDeviceId = '') then
  begin
    P := Pos('DEVICE_ID=', ALine);
    S := Copy(ALine, P + 10, MaxInt);
    P2 := Pos(':', S);
    if P2 > 0 then S := Copy(S, 1, P2 - 1);
    P2 := Pos(' ', S);
    if P2 > 0 then S := Copy(S, 1, P2 - 1);
    FSerialDeviceId := Trim(S);
    if edtDeviceId.Text = '' then edtDeviceId.Text := FSerialDeviceId;
  end;

  if (Pos('MAC=', ALine) > 0) and (FSerialMac = '') then
  begin
    P := Pos('MAC=', ALine);
    S := Copy(ALine, P + 4, MaxInt);
    P2 := Pos(':', S);
    if P2 > 0 then S := Copy(S, 1, P2 - 1);
    P2 := Pos(' ', S);
    if P2 > 0 then S := Copy(S, 1, P2 - 1);
    FSerialMac := Trim(S);
    if edtMac.Text = '' then edtMac.Text := FSerialMac;
  end;

  if (Pos('TOKEN=', ALine) > 0) then
  begin
    P := Pos('TOKEN=', ALine);
    S := Copy(ALine, P + 6, MaxInt);
    P2 := Pos(':', S);
    if P2 > 0 then S := Copy(S, 1, P2 - 1);
    P2 := Pos(' ', S);
    if P2 > 0 then S := Copy(S, 1, P2 - 1);
    S := Trim(S);
    if (S <> '') and (S <> 'NAO') and (S <> 'SIM') and (S <> 'vazio') then
    begin
      FSerialToken := S;
      if edtToken.Text = '' then edtToken.Text := FSerialToken;
    end;
  end;

  if Pos('CAM_STAGE:WIFI_OK:', ALine) = 1 then
  begin
    P := Pos(':IP=', ALine);
    if P > 0 then
    begin
      S := Copy(ALine, P + 4, MaxInt);
      P2 := Pos(':', S);
      if P2 > 0 then S := Copy(S, 1, P2 - 1);
      S := Trim(S);
      if (S <> '') and (S <> '0.0.0.0') then
      begin
        FSerialIp := S;
        edtIp.Text := FSerialIp;
        FSerialWifiOkSeen := True;
      end;
    end;
  end;

  if (Pos('[CASA-CAM-ONLINE]', ALine) = 1) and (Pos('IP=', ALine) > 0) then
  begin
    P := Pos('IP=', ALine);
    S := Trim(Copy(ALine, P + 3, MaxInt));
    if (S <> '') and (S <> '0.0.0.0') then
    begin
      FSerialIp := S;
      edtIp.Text := FSerialIp;
      FSerialWifiOkSeen := True;
    end;
  end;

  OnFlasherLog(nil, '[CAMERA] ' + TAIFirmwareFlasher.MaskTokens(ALine));
  Desc := TAIFirmwareFlasher.DescribeFirmwareLine(ALine);
  if Desc <> '' then
    OnFlasherLog(nil, '         -> ' + Desc);
end;

procedure TFormMain.tmrSerialTimer(Sender: TObject);
var
  Buf: array[0..1023] of Char;
  N, I: Integer;
begin
  if not FSerialOpen then Exit;
  tmrSerial.Enabled := False;  // evita reentrada (OnFlasherLog processa mensagens)
  try
    repeat
      N := SerRead(FSerialHandle, Buf, SizeOf(Buf));
      if N > 0 then
      begin
        Inc(FSerialBytes, N);
        FSerialLastByte := GetTickCount64;
        FSerialSilenceWarned := False;
        for I := 0 to N - 1 do
          if Buf[I] in [#10, #13] then
          begin
            if Trim(FSerialLine) <> '' then
              HandleSerialLine(Trim(FSerialLine));
            FSerialLine := '';
          end
          else
            FSerialLine := FSerialLine + Buf[I];
      end;
    until N <= 0;

    if (not FSerialSilenceWarned) and (GetTickCount64 - FSerialLastByte >= 20000) then
    begin
      FSerialSilenceWarned := True;
      if FSerialBytes = 0 then
        OnFlasherLog(nil, '[MONITOR] Nenhum byte da camera desde que a porta abriu. Ela nao esta rodando o firmware: ' +
          'tire o jumper IO0->GND e aperte RST; confira 5V, GND comum e o fio U0T->RX do adaptador.')
      else
        OnFlasherLog(nil, '[MONITOR] Camera em silencio ha 20 s (o firmware imprime uma linha a cada 15 s quando esta rodando).');
    end;
  finally
    tmrSerial.Enabled := FSerialOpen;
  end;
end;

procedure TFormMain.chkSerialMonChange(Sender: TObject);
begin
  if FUpdatingMonitorCheck then Exit;
  if chkSerialMon.Checked then
    StartSerialMonitor
  else
    StopSerialMonitor;
end;

{ Depois da gravacao a camera continua parada no carregador do esptool ate o
  usuario tirar o jumper IO0 e apertar RST. Espera o monitor serial ver o reset. }
function TFormMain.SendSerialCommand(const ACmd: string): Boolean;
var
  S: AnsiString;
begin
  Result := False;
  if not FSerialOpen then Exit;
  S := ACmd + #10;
  Result := SerWrite(FSerialHandle, S[1], Length(S)) = Length(S);
  OnFlasherLog(nil, '[SERIAL-TX] ' + ACmd);
end;

function TFormMain.ProvisionViaSerial(const AToken, ACasaUrl: string; AWifis: TStrings): Boolean;
var
  I: Integer;
  Ssid, Senha: string;
  StartWait: QWord;
begin
  Result := False;
  if not FSerialOpen then
  begin
    StartSerialMonitor;
    if not FSerialOpen then Exit;
  end;

  ProvStage('Falando com a camera pela Serial...');
  FSerialReadySeen := False;
  FSerialWifiOkSeen := False;

  // Envia PING repetido para acordar a camera e solicitar informacoes
  for I := 1 to 3 do
  begin
    SendSerialCommand('PING');
    SendSerialCommand('INFO');
    WaitUi(300);
    if FSerialDeviceId <> '' then Break;
  end;

  if FSerialDeviceId = '' then
  begin
    StartWait := GetTickCount64;
    while (GetTickCount64 - StartWait < 3000) and (FSerialDeviceId = '') do
    begin
      SendSerialCommand('INFO');
      WaitUi(500);
    end;
  end;

  // 1. Grava o token
  if AToken <> '' then
  begin
    ProvStage('Gravando token pela serial...');
    SendSerialCommand('SET_TOKEN:' + AToken);
    WaitUi(250);
  end;

  // 2. Grava as redes da casa
  ProvStage('Gravando redes Wi-Fi da casa pela serial...');
  SendSerialCommand('CLEAR_WIFIS');
  WaitUi(250);

  for I := 0 to AWifis.Count - 1 do
  begin
    if ParseWifiLine(AWifis[I], Ssid, Senha) then
    begin
      ProvLog(Format('Enviando rede Wi-Fi via serial: %s', [Ssid]));
      SendSerialCommand('ADD_WIFI:' + Ssid + ':' + Senha);
      WaitUi(250);
    end;
  end;

  // 3. Grava URL do site CASA se houver
  if ACasaUrl <> '' then
  begin
    SendSerialCommand('SET_CASA_URL:' + ACasaUrl);
    WaitUi(200);
  end;

  // 4. Reinicia a camera para aplicar a configuracao
  ProvStage('Configuracao gravada pela serial. Reiniciando a camera...');
  FSerialWifiOkSeen := False;
  FSerialIp := '';
  SendSerialCommand('RESTART');
  WaitUi(1500);

  // 5. Monitora a serial aguardando a conexao no Wi-Fi e exibicao do IP
  ProvStage('Aguardando a camera conectar no Wi-Fi da casa pela console serial...');
  StartWait := GetTickCount64;
  while (GetTickCount64 - StartWait < 45000) do
  begin
    if FUiCancel then Exit(False);
    if FSerialWifiOkSeen and (FSerialIp <> '') and (FSerialIp <> '0.0.0.0') then
    begin
      ProvLog(Format('Camera conectada com sucesso no Wi-Fi! IP=%s', [FSerialIp]));
      Result := True;
      Exit;
    end;
    WaitUi(400);
  end;

  ProvLog('[AVISO] Redes gravadas pela serial. Tentando localizar na rede local...');
  Result := True;
end;

function TFormMain.WaitCameraReset(ATimeoutMs: Integer): Boolean;
var
  Inicio: QWord;
  AvisouDownload: Boolean;
begin
  Result := False;
  FSerialBootSeen := False;
  FSerialDownloadSeen := False;
  FSerialApSeen := False;
  AvisouDownload := False;
  Inicio := GetTickCount64;
  OnFlasherLog(nil, '[INFO] Aguardando a camera reiniciar: tire o jumper IO0->GND e aperte RST.');
  while GetTickCount64 - Inicio < QWord(ATimeoutMs) do
  begin
    if FSerialBootSeen then
    begin
      OnFlasherLog(nil, '[INFO] Reset detectado pela serial: a camera esta rodando o firmware.');
      Exit(True);
    end;
    if FSerialDownloadSeen and not AvisouDownload then
    begin
      AvisouDownload := True;
      OnFlasherLog(nil, '[AVISO] A camera reiniciou em MODO DE GRAVACAO: o jumper IO0->GND ainda esta ligado.');
    end;
    if FSerialDownloadSeen then
      ProvStage('A camera reiniciou em MODO DE GRAVACAO: TIRE o jumper IO0->GND e APERTE RST de novo.')
    else
      ProvStage(Format('Firmware gravado. TIRE o jumper IO0->GND e APERTE o botao RST da camera... (%ds)',
        [(GetTickCount64 - Inicio) div 1000]));
    if not WaitUi(500) then Exit;
  end;
  if FSerialBytes = 0 then
    FProv.Fail('A camera nao reiniciou: nenhuma linha na serial em ' + IntToStr(ATimeoutMs div 1000) +
      's depois da gravacao. Tire o jumper IO0->GND e aperte RST; se ja fez isso, confira o botao RST, ' +
      'a alimentacao de 5V e o fio U0T->RX do adaptador.')
  else
    FProv.Fail('A camera mandou dados pela serial mas nao reiniciou rodando o firmware ' +
      '(veja as linhas [CAMERA] no log).');
end;

procedure TFormMain.btnGetTokenClick(Sender: TObject);
begin
  RunProvisioning(False);
end;

procedure TFormMain.btnGenNewTokenClick(Sender: TObject);
begin
  edtToken.Text := TAIFirmwareFlasher.GenerateUniqueToken;
  ProvLog('Novo token gerado no PC; sera gravado na camera no proximo provisionamento.');
end;

procedure TFormMain.btnLoadWifisClick(Sender: TObject);
begin
  SetBusy(True);
  try
    if FProv.LoadHouseWifisFromSite(SiteBaseUrl, Trim(edtApiKey.Text), memWifis.Lines) then
      ShowStage(Format('%d rede(s) carregada(s) do site. Revise antes de provisionar.', [memWifis.Lines.Count]), $00F8E8D0)
    else
    begin
      ShowStage('FALHOU: ' + FProv.LastError, $00C8C8F8);
      ShowMessage(FProv.LastError);
    end;
  finally
    SetBusy(False);
  end;
end;

procedure TFormMain.btnCancelClick(Sender: TObject);
begin
  FUiCancel := True;
  FFlasher.CancelSerial;
  FProv.Cancel;
  ShowStage('Cancelando...', $00C8E6F0);
end;

procedure TFormMain.OnFlasherStage(Sender: TObject; const AStage: string);
begin
  // Pedido de acao do usuario em amarelo; andamento normal em azul claro
  if Pos('PRESSIONE', AStage) > 0 then
    ShowStage(AStage, $0080FFFF)
  else if Pos('MODO DE GRAVACAO', AStage) > 0 then
    ShowStage(AStage, $0080C0FF)
  else
    ShowStage(AStage, $00F8E8D0);
end;

procedure TFormMain.btnSendDaemonClick(Sender: TObject);
begin
  if (Trim(edtToken.Text) = '') or (Trim(edtDeviceId.Text) = '') then
  begin
    ShowMessage('Provisione a camera primeiro (device_id e token vazios).');
    Exit;
  end;
  FProv.DeviceId := Trim(edtDeviceId.Text);
  FProv.Mac := Trim(edtMac.Text);
  FProv.Ip := Trim(edtIp.Text);
  if FProv.Porta = 0 then FProv.Porta := 80;
  if FProv.PortaStream = 0 then FProv.PortaStream := FProv.Porta;
  SetBusy(True);
  try
    if FProv.RegisterOnSite(Trim(edtDaemonUrl.Text), Trim(edtApiKey.Text), Trim(edtToken.Text)) then
    begin
      ShowStage('Camera ' + FProv.DeviceId + ' registrada no site.', $00C8F0C8);
      ShowMessage('Camera registrada na tabela de cameras do site CASA.');
    end
    else
    begin
      ShowStage('FALHOU: ' + FProv.LastError, $00C8C8F8);
      ShowMessage(FProv.LastError);
    end;
  finally
    SetBusy(False);
  end;
end;

procedure TFormMain.btnCopyTokenClick(Sender: TObject);
begin
  if edtToken.Text <> '' then
  begin
    Clipboard.AsText := edtToken.Text;
    ShowMessage('Token copiado para a area de transferencia!');
  end;
end;

procedure TFormMain.btnClearLogClick(Sender: TObject);
begin
  memLog.Clear;
end;

procedure TFormMain.AppendLogFile(const AMessage: string);
var
  F: TextFile;
  FileName: string;
begin
  // Copia do terminal em arquivo, para conferir/enviar depois
  FileName := ExtractFilePath(ParamStr(0)) + 'gravafirmware.log';
  try
    AssignFile(F, FileName);
    if FileExists(FileName) then Append(F) else Rewrite(F);
    try
      WriteLn(F, FormatDateTime('yyyy-mm-dd hh:nn:ss', Now) + ' ' + AMessage);
    finally
      CloseFile(F);
    end;
  except
    // log em arquivo e opcional (pasta sem permissao de escrita)
  end;
end;

procedure TFormMain.OnFlasherLog(Sender: TObject; const AMessage: string);
begin
  memLog.Lines.Add(FormatDateTime('hh:nn:ss', Now) + ' ' + AMessage);
  AppendLogFile(AMessage);
  // Auto-scroll to end
  memLog.SelStart := Length(memLog.Text);
  Application.ProcessMessages;
end;

procedure TFormMain.OnFlasherProgress(Sender: TObject; APercent: Integer);
begin
  if APercent >= 0 then
  begin
    pbProgress.Position := APercent;
    lblProgress.Caption := IntToStr(APercent) + '%';
    Application.ProcessMessages;
  end;
end;

procedure TFormMain.OnFlasherFinished(Sender: TObject; ASuccess: Boolean; const AErrorMsg: string);
begin
  if ASuccess then
  begin
    lblProgress.Caption := '100% - Sucesso!';
    if not chkAutoProv.Checked then
      ShowMessage('Gravacao de firmware concluida com SUCESSO!' + LineEnding + LineEnding +
                  'Proximo passo:' + LineEnding +
                  '1. Desconecte o jumper IO0 do GND.' + LineEnding +
                  '2. Pressione o botao RST da camera (ela cria o Wi-Fi espcam).' + LineEnding +
                  '3. Clique em "2. Provisionar (espcam)".');
  end
  else
  begin
    lblProgress.Caption := 'Falha na gravacao';
    ShowMessage('Erro na gravacao do firmware: ' + LineEnding + AErrorMsg);
  end;
end;

end.

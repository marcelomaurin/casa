unit frmmain;

{$mode objfpc}{$H+}

interface

uses
  Classes, SysUtils, Forms, Controls, Graphics, Dialogs, StdCtrls, ExtCtrls,
  ComCtrls, Clipbrd, aifirmwareflasher;

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
    btnSendDaemon: TButton;
    pbProgress: TProgressBar;
    lblProgress: TLabel;
    lblDaemonUrl: TLabel;
    edtDaemonUrl: TEdit;
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

    procedure FormCreate(Sender: TObject);
    procedure FormDestroy(Sender: TObject);
    procedure btnRefreshPortsClick(Sender: TObject);
    procedure btnBrowseClick(Sender: TObject);
    procedure btnFlashClick(Sender: TObject);
    procedure btnGetTokenClick(Sender: TObject);
    procedure btnSendDaemonClick(Sender: TObject);
    procedure btnCopyTokenClick(Sender: TObject);
    procedure btnClearLogClick(Sender: TObject);
    procedure cbBoardChange(Sender: TObject);
  private
    FFlasher: TAIFirmwareFlasher;
    procedure OnFlasherLog(Sender: TObject; const AMessage: string);
    procedure OnFlasherProgress(Sender: TObject; APercent: Integer);
    procedure OnFlasherFinished(Sender: TObject; ASuccess: Boolean; const AErrorMsg: string);
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

  RefreshPortList;

  DefaultBin := 'N:\maurinsoft\casa\arduino\esp32_cam\jarvis_espcam\build\jarvis_espcam.ino.merged.bin';
  if FileExists(DefaultBin) then
    edtFirmwarePath.Text := DefaultBin
  else
    edtFirmwarePath.Text := 'N:\maurinsoft\casa\arduino\esp32_cam\jarvis_espcam\build\jarvis_espcam.ino.bin';

  edtDaemonUrl.Text := 'https://maurinsoft.com.br/casa/api/camera_discovery.php?acao=register_token';
  UpdateBoardDefaults;
end;

procedure TFormMain.FormDestroy(Sender: TObject);
begin
  // FFlasher freed by Self ownership
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
  case cbBoard.ItemIndex of
    0: // ESP32-CAM
    begin
      cbBaudRate.Text := '921600';
      edtAddress.Text := '0x0';
    end;
    1: // ESP32 Dev Module
    begin
      cbBaudRate.Text := '921600';
      edtAddress.Text := '0x10000';
    end;
    2: // Arduino Uno
    begin
      cbBaudRate.Text := '115200';
      edtAddress.Text := 'N/A';
    end;
    3: // Arduino Nano
    begin
      cbBaudRate.Text := '115200';
      edtAddress.Text := 'N/A';
    end;
    4: // Arduino Mega
    begin
      cbBaudRate.Text := '115200';
      edtAddress.Text := 'N/A';
    end;
  end;
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
begin
  case cbBoard.ItemIndex of
    0: FFlasher.BoardType := fbtESP32CAM;
    1: FFlasher.BoardType := fbtESP32;
    2: FFlasher.BoardType := fbtArduinoUno;
    3: FFlasher.BoardType := fbtArduinoNano;
    4: FFlasher.BoardType := fbtArduinoMega;
  end;

  FFlasher.Port := Trim(cbPort.Text);
  FFlasher.BaudRate := StrToIntDef(cbBaudRate.Text, 921600);
  FFlasher.FirmwareFile := Trim(edtFirmwarePath.Text);
  FFlasher.FlashAddress := Trim(edtAddress.Text);

  btnFlash.Enabled := False;
  btnGetToken.Enabled := False;
  btnSendDaemon.Enabled := False;
  pbProgress.Position := 0;
  lblProgress.Caption := 'Gravando...';

  Screen.Cursor := crHourGlass;
  try
    Application.ProcessMessages;
    FFlasher.FlashFirmware;
  finally
    Screen.Cursor := crDefault;
    btnFlash.Enabled := True;
    btnGetToken.Enabled := True;
    btnSendDaemon.Enabled := True;
  end;
end;

procedure TFormMain.btnGetTokenClick(Sender: TObject);
var
  Ret: string;
begin
  FFlasher.Port := Trim(cbPort.Text);
  FFlasher.SerialBaudRate := 115200;

  btnGetToken.Enabled := False;
  Screen.Cursor := crHourGlass;
  try
    memLog.Lines.Add('----------------------------------------------------');
    memLog.Lines.Add('[INFO] ATENCAO: Certifique-se de que o jumper IO0 -> GND foi removido e aperte o botao RST da placa.');
    Application.ProcessMessages;

    Ret := FFlasher.RequestSerialToken(6000);
    edtToken.Text := FFlasher.CapturedToken;
    edtDeviceId.Text := FFlasher.CapturedDeviceId;
    edtMac.Text := FFlasher.CapturedMac;
    edtIp.Text := FFlasher.CapturedIp;

    if Ret <> '' then
    begin
      ShowMessage('Token capturado com sucesso!' + LineEnding +
                  'Dispositivo: ' + FFlasher.CapturedDeviceId + LineEnding +
                  'Token: ' + FFlasher.CapturedToken);
    end
    else
    begin
      ShowMessage('Nao foi possivel capturar o token.' + LineEnding +
                  'Erro: ' + FFlasher.LastError);
    end;
  finally
    Screen.Cursor := crDefault;
    btnGetToken.Enabled := True;
  end;
end;

procedure TFormMain.btnSendDaemonClick(Sender: TObject);
var
  Ok: Boolean;
begin
  if Trim(edtToken.Text) = '' then
  begin
    ShowMessage('Nenhum token presente para envio. Capture pela serial primeiro.');
    Exit;
  end;

  FFlasher.CapturedToken := Trim(edtToken.Text);
  FFlasher.CapturedDeviceId := Trim(edtDeviceId.Text);
  FFlasher.CapturedMac := Trim(edtMac.Text);
  FFlasher.CapturedIp := Trim(edtIp.Text);
  FFlasher.DaemonUrl := Trim(edtDaemonUrl.Text);

  btnSendDaemon.Enabled := False;
  try
    Ok := FFlasher.SendTokenToDaemon(Trim(edtDaemonUrl.Text));
    if Ok then
      ShowMessage('Token registrado com sucesso no site CASA (https://maurinsoft.com.br/casa/)! O daemon unico obtera a chave pela API.' + LineEnding +
                  'O daemon agora esta autorizado a ler fotos e streams desta camera.')
    else
      ShowMessage('Aviso: ' + FFlasher.LastError);
  finally
    btnSendDaemon.Enabled := True;
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

procedure TFormMain.OnFlasherLog(Sender: TObject; const AMessage: string);
begin
  memLog.Lines.Add(AMessage);
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
    ShowMessage('Gravacao de firmware concluida com SUCESSO!' + LineEnding + LineEnding +
                'Proximo passo:' + LineEnding +
                '1. Desconecte o jumper IO0 do GND.' + LineEnding +
                '2. Pressione o botao RST da camera.' + LineEnding +
                '3. Clique em "2. Capturar Token (Serial)".');
  end
  else
  begin
    lblProgress.Caption := 'Falha na gravacao';
    ShowMessage('Erro na gravacao do firmware: ' + LineEnding + AErrorMsg);
  end;
end;

end.

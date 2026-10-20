unit espcamprov;

{ Provisionamento da ESP32-CAM pela rede Wi-Fi "espcam" que o firmware cria
  quando esta sem configuracao, e localizacao da camera na rede da casa.

  Fluxo usado pelo gravafirmware:
    ConnectToEspcam  -> netsh conecta o Wi-Fi do PC na rede espcam
    ProvisionGet     -> GET  http://192.168.4.1/provision
    ProvisionSet     -> POST http://192.168.4.1/provision (token + redes); camera reinicia
    RestoreWifi      -> PC volta para a rede em que estava
    DiscoverCamera   -> broadcast UDP "ESPCAM_DISCOVER" na rede da casa (porta 32108)
    RegisterOnSite   -> acao=register_token no site CASA (tipo, IP e portas) }

{$mode objfpc}{$H+}

interface

uses
  Classes, SysUtils, Process, fphttpclient, opensslsockets, fpjson, jsonparser, LazUTF8,
  sockets {$IFDEF MSWINDOWS}, WinSock2{$ENDIF};

const
  ESPCAM_SSID = 'espcam';
  ESPCAM_AP_URL = 'http://192.168.4.1';
  ESPCAM_DISCOVERY_PORT = 32108;

type
  TProvTextEvent = procedure(const AText: string) of object;

  { TEspCamProvisioner }

  TEspCamProvisioner = class
  private
    FOnLog: TProvTextEvent;
    FOnStage: TProvTextEvent;
    FOnIdle: TNotifyEvent;
    FCancel: Boolean;
    FPreviousSsid: string;
    FLastError: string;
    procedure Log(const AMsg: string);
    procedure Stage(const AMsg: string);
    function Wait(AMs: Integer): Boolean;
    function Netsh(const AArgs: array of string; out AOutput: string): Boolean;
    function HttpRequest(const AMethod, AUrl, ABody, AApiKey: string; ATimeoutMs: Integer;
      out ACode: Integer; out AResponse: string): Boolean;
  public
    { Dados da camera (preenchidos por ProvisionGet/DiscoverCamera) }
    DeviceId, Mac, Versao, TokenAtual, Ip: string;
    Porta, PortaStream: Integer;
    RedesGravadas: string;

    procedure Fail(const AMsg: string);
    function CurrentWifiSsid: string;
    function IsEspcamVisible: Boolean;
    function ConnectToEspcam(ATimeoutMs: Integer): Boolean;
    function ProvisionGet: Boolean;
    function ProvisionSet(const AToken, ACasaUrl: string; AWifis: TStrings): Boolean;
    function RestoreWifi: Boolean;
    function DiscoverCamera(const ADeviceId, ASiteBaseUrl, AApiKey: string; ATimeoutMs: Integer): Boolean;
    function RegisterOnSite(const ARegisterUrl, AApiKey, AToken: string): Boolean;
    function LoadHouseWifisFromSite(const ASiteBaseUrl, AApiKey: string; ADest: TStrings): Boolean;
    procedure Cancel;

    property PreviousSsid: string read FPreviousSsid;
    property LastError: string read FLastError;
    property OnLog: TProvTextEvent read FOnLog write FOnLog;
    property OnStage: TProvTextEvent read FOnStage write FOnStage;
    { Chamado durante as esperas para a interface continuar respondendo }
    property OnIdle: TNotifyEvent read FOnIdle write FOnIdle;
  end;

{ Converte linhas "SSID;senha" em pares; ignora linhas vazias e comentarios (#) }
function ParseWifiLine(const ALine: string; out ASsid, ASenha: string): Boolean;

implementation

function ParseWifiLine(const ALine: string; out ASsid, ASenha: string): Boolean;
var
  P: Integer;
  L: string;
begin
  L := Trim(ALine);
  ASsid := '';
  ASenha := '';
  Result := False;
  if (L = '') or (L[1] = '#') then Exit;
  P := Pos(';', L);
  if P > 0 then
  begin
    ASsid := Trim(Copy(L, 1, P - 1));
    ASenha := Copy(L, P + 1, MaxInt);
  end
  else
    ASsid := L;
  Result := ASsid <> '';
end;

{ TEspCamProvisioner }

procedure TEspCamProvisioner.Log(const AMsg: string);
begin
  if Assigned(FOnLog) then FOnLog(AMsg);
end;

procedure TEspCamProvisioner.Stage(const AMsg: string);
begin
  Log('[ETAPA] ' + AMsg);
  if Assigned(FOnStage) then FOnStage(AMsg);
end;

procedure TEspCamProvisioner.Fail(const AMsg: string);
begin
  FLastError := AMsg;
  Log('[ERRO] ' + AMsg);
end;

procedure TEspCamProvisioner.Cancel;
begin
  FCancel := True;
end;

function TEspCamProvisioner.Wait(AMs: Integer): Boolean;
var
  Fim: QWord;
begin
  Fim := GetTickCount64 + QWord(AMs);
  while GetTickCount64 < Fim do
  begin
    if FCancel then Exit(False);
    if Assigned(FOnIdle) then FOnIdle(Self);
    Sleep(50);
  end;
  Result := not FCancel;
end;

function TEspCamProvisioner.Netsh(const AArgs: array of string; out AOutput: string): Boolean;
begin
  AOutput := '';
  Result := RunCommand('netsh', AArgs, AOutput, [poNoConsole]);
  // netsh escreve na pagina de codigo do console (cp850): converte para UTF-8 da LCL
  AOutput := ConsoleToUTF8(AOutput);
end;

function TEspCamProvisioner.CurrentWifiSsid: string;
var
  Output, Line: string;
  Lines: TStringList;
  P: Integer;
begin
  Result := '';
  if not Netsh(['wlan', 'show', 'interfaces'], Output) then Exit;
  Lines := TStringList.Create;
  try
    Lines.Text := Output;
    for Line in Lines do
    begin
      // "    SSID                   : nome"  (a linha BSSID e ignorada)
      if (Pos('SSID', TrimLeft(Line)) = 1) then
      begin
        P := Pos(':', Line);
        if P > 0 then
        begin
          Result := Trim(Copy(Line, P + 1, MaxInt));
          Exit;
        end;
      end;
    end;
  finally
    Lines.Free;
  end;
end;

function TEspCamProvisioner.IsEspcamVisible: Boolean;
var
  Output: string;
begin
  Result := Netsh(['wlan', 'show', 'networks'], Output) and
    (Pos(': ' + ESPCAM_SSID + #13, Output + #13) > 0);
end;

function TEspCamProvisioner.HttpRequest(const AMethod, AUrl, ABody, AApiKey: string;
  ATimeoutMs: Integer; out ACode: Integer; out AResponse: string): Boolean;
var
  C: TFPHTTPClient;
  Body: TStringStream;
begin
  Result := False;
  ACode := 0;
  AResponse := '';
  C := TFPHTTPClient.Create(nil);
  Body := nil;
  try
    C.ConnectTimeout := ATimeoutMs;
    C.IOTimeout := ATimeoutMs;
    C.AllowRedirect := True;
    if AApiKey <> '' then
      C.AddHeader('X-API-Key', AApiKey);
    if ABody <> '' then
    begin
      C.AddHeader('Content-Type', 'application/json');
      Body := TStringStream.Create(ABody);
      C.RequestBody := Body;
    end;
    try
      if AMethod = 'POST' then
        AResponse := C.Post(AUrl)
      else
        AResponse := C.Get(AUrl);
      ACode := C.ResponseStatusCode;
      Result := True;
    except
      on E: Exception do
      begin
        ACode := C.ResponseStatusCode;
        AResponse := E.ClassName + ': ' + E.Message;
      end;
    end;
  finally
    Body.Free;
    C.Free;
  end;
end;

function TEspCamProvisioner.ConnectToEspcam(ATimeoutMs: Integer): Boolean;
var
  Xml: TStringList;
  ProfileFile, Output, Resp, Ssid: string;
  Code: Integer;
  Inicio, UltimoConnect: QWord;
  JaViu: Boolean;
begin
  Result := False;
  FCancel := False;
  FLastError := '';

  FPreviousSsid := CurrentWifiSsid;
  if SameText(FPreviousSsid, ESPCAM_SSID) then
    FPreviousSsid := '';
  if FPreviousSsid <> '' then
    Log('Wi-Fi atual do PC: ' + FPreviousSsid + ' (sera restaurado no final)')
  else
    Log('PC sem Wi-Fi conectado no momento (provavelmente no cabo); no final o Wi-Fi so sera desconectado do espcam.');

  // Perfil da rede espcam, que e aberta (conexao manual: o Windows nao entra nela sozinho depois)
  ProfileFile := GetTempDir(False) + 'espcam_wlan_profile.xml';
  Xml := TStringList.Create;
  try
    Xml.Text :=
      '<?xml version="1.0"?>' + LineEnding +
      '<WLANProfile xmlns="http://www.microsoft.com/networking/WLAN/profile/v1">' + LineEnding +
      ' <name>' + ESPCAM_SSID + '</name>' + LineEnding +
      ' <SSIDConfig><SSID><name>' + ESPCAM_SSID + '</name></SSID></SSIDConfig>' + LineEnding +
      ' <connectionType>ESS</connectionType>' + LineEnding +
      ' <connectionMode>manual</connectionMode>' + LineEnding +
      ' <MSM><security>' + LineEnding +
      '  <authEncryption><authentication>open</authentication><encryption>none</encryption><useOneX>false</useOneX></authEncryption>' + LineEnding +
      ' </security></MSM>' + LineEnding +
      '</WLANProfile>';
    Xml.SaveToFile(ProfileFile);
  finally
    Xml.Free;
  end;
  Netsh(['wlan', 'add', 'profile', 'filename=' + ProfileFile, 'user=current'], Output);
  Log('[NETSH] ' + Trim(Output));

  Inicio := GetTickCount64;
  UltimoConnect := 0;
  JaViu := False;
  while GetTickCount64 - Inicio < QWord(ATimeoutMs) do
  begin
    if FCancel then
    begin
      Fail('Cancelado pelo usuario.');
      Exit;
    end;

    Ssid := CurrentWifiSsid;
    if SameText(Ssid, ESPCAM_SSID) then
    begin
      Stage('PC conectado na rede espcam. Falando com a camera em ' + ESPCAM_AP_URL + '...');
      if HttpRequest('GET', ESPCAM_AP_URL + '/provision', '', '', 2500, Code, Resp) and (Code = 200) then
      begin
        Log('Camera respondeu no espcam.');
        Exit(True);
      end;
      Log('Ainda sem resposta HTTP da camera (' + Resp + '); aguardando o IP do espcam...');
    end
    else if GetTickCount64 - UltimoConnect >= 8000 then
    begin
      UltimoConnect := GetTickCount64;
      if not JaViu and IsEspcamVisible then
      begin
        JaViu := True;
        Log('Rede espcam visivel para o Windows.');
      end;
      Stage(Format('Procurando a rede Wi-Fi espcam e conectando... %ds de %ds',
        [(GetTickCount64 - Inicio) div 1000, ATimeoutMs div 1000]));
      Netsh(['wlan', 'connect', 'name=' + ESPCAM_SSID, 'ssid=' + ESPCAM_SSID], Output);
      Log('[NETSH] ' + Trim(Output));
    end;
    if not Wait(1000) then Continue;
  end;

  if JaViu then
    Fail('A rede espcam apareceu, mas o PC nao conseguiu conectar/obter resposta da camera em ' +
      IntToStr(ATimeoutMs div 1000) + 's.')
  else
    Fail('A rede Wi-Fi espcam nao apareceu em ' + IntToStr(ATimeoutMs div 1000) + 's. ' +
      'A camera reiniciou fora do modo de gravacao (jumper IO0 removido + RST)? ' +
      'Ela so cria o espcam quando esta sem redes configuradas.');
end;

function TEspCamProvisioner.ProvisionGet: Boolean;
var
  Code: Integer;
  Resp: string;
  J: TJSONData;
  O: TJSONObject;
  Arr: TJSONArray;
  I: Integer;
begin
  Result := False;
  Stage('Lendo a camera (GET /provision)...');
  if not HttpRequest('GET', ESPCAM_AP_URL + '/provision', '', '', 5000, Code, Resp) or (Code <> 200) then
  begin
    Fail('GET /provision falhou (HTTP ' + IntToStr(Code) + '): ' + Resp);
    Exit;
  end;
  try
    J := GetJSON(Resp);
  except
    on E: Exception do
    begin
      Fail('Resposta invalida do GET /provision: ' + Copy(Resp, 1, 200));
      Exit;
    end;
  end;
  try
    if not (J is TJSONObject) then
    begin
      Fail('Resposta inesperada do GET /provision.');
      Exit;
    end;
    O := TJSONObject(J);
    DeviceId := O.Get('device_id', '');
    Mac := O.Get('mac', '');
    Versao := O.Get('versao', '');
    TokenAtual := O.Get('token', '');
    RedesGravadas := '';
    Arr := O.Get('wifis', TJSONArray(nil));
    if Assigned(Arr) then
      for I := 0 to Arr.Count - 1 do
        if Arr.Items[I] is TJSONObject then
          RedesGravadas := RedesGravadas + TJSONObject(Arr.Items[I]).Get('ssid', '') + ' ';
    Log(Format('Camera: device_id=%s mac=%s firmware=%s token=%s redes=[%s]',
      [DeviceId, Mac, Versao, BoolToStr(TokenAtual <> '', 'gravado', 'vazio'), Trim(RedesGravadas)]));
    Result := DeviceId <> '';
    if not Result then
      Fail('A camera nao informou o device_id.');
  finally
    J.Free;
  end;
end;

function TEspCamProvisioner.ProvisionSet(const AToken, ACasaUrl: string; AWifis: TStrings): Boolean;
var
  O, W: TJSONObject;
  Arr: TJSONArray;
  I, Code: Integer;
  Ssid, Senha, Resp: string;
begin
  Result := False;
  O := TJSONObject.Create;
  try
    O.Add('token', AToken);
    if ACasaUrl <> '' then
      O.Add('casa_url', ACasaUrl);
    Arr := TJSONArray.Create;
    for I := 0 to AWifis.Count - 1 do
      if ParseWifiLine(AWifis[I], Ssid, Senha) then
      begin
        W := TJSONObject.Create;
        W.Add('ssid', Ssid);
        W.Add('senha', Senha);
        Arr.Add(W);
      end;
    O.Add('wifis', Arr);
    if Arr.Count = 0 then
    begin
      Fail('Nenhuma rede Wi-Fi da casa informada.');
      Exit;
    end;
    Stage(Format('Gravando na camera: token + %d rede(s) Wi-Fi (POST /provision)...', [Arr.Count]));
    if not HttpRequest('POST', ESPCAM_AP_URL + '/provision', O.AsJSON, '', 8000, Code, Resp) or (Code <> 200) then
    begin
      Fail('POST /provision falhou (HTTP ' + IntToStr(Code) + '): ' + Resp);
      Exit;
    end;
    Log('Camera confirmou: ' + Resp);
    Result := True;
  finally
    O.Free;
  end;
end;

function TEspCamProvisioner.RestoreWifi: Boolean;
var
  Output: string;
  Inicio: QWord;
begin
  Result := True;
  Stage('Saindo da rede espcam e voltando para a rede normal...');
  if SameText(CurrentWifiSsid, ESPCAM_SSID) then
  begin
    Netsh(['wlan', 'disconnect'], Output);
    Log('[NETSH] ' + Trim(Output));
  end;
  if FPreviousSsid = '' then
    Exit;
  Netsh(['wlan', 'connect', 'name=' + FPreviousSsid], Output);
  Log('[NETSH] ' + Trim(Output));
  Inicio := GetTickCount64;
  while GetTickCount64 - Inicio < 30000 do
  begin
    if SameText(CurrentWifiSsid, FPreviousSsid) then
    begin
      Log('PC de volta na rede ' + FPreviousSsid + '.');
      Wait(3000);  // DHCP/rota
      Exit;
    end;
    if not Wait(1000) then Break;
  end;
  Result := False;
  Fail('Nao consegui reconectar o PC na rede ' + FPreviousSsid + '. Conecte manualmente.');
end;

function TEspCamProvisioner.DiscoverCamera(const ADeviceId, ASiteBaseUrl, AApiKey: string;
  ATimeoutMs: Integer): Boolean;
var
  S: LongInt;  // fpSocket devolve -1 em erro (TSocket do WinSock2 e sem sinal)
  Addr, From: TInetSockAddr;
  FromLen: TSockLen;
  Opt: LongInt;
  Tv: DWord;
  Msg, Resp, FoundId: string;
  Buf: array[0..1023] of Char;
  N, Code: Integer;
  Inicio, UltimoEnvio, UltimoSite: QWord;
  J: TJSONData;
  O: TJSONObject;
  Arr: TJSONArray;
  I: Integer;
begin
  Result := False;
  Ip := '';
  Porta := 80;
  PortaStream := 80;
  S := fpSocket(AF_INET, SOCK_DGRAM, 0);
  if S < 0 then
  begin
    Fail('Nao foi possivel criar o socket UDP de descoberta.');
    Exit;
  end;
  try
    Opt := 1;
    fpSetSockOpt(S, SOL_SOCKET, SO_BROADCAST, @Opt, SizeOf(Opt));
    Tv := 300;
    fpSetSockOpt(S, SOL_SOCKET, SO_RCVTIMEO, @Tv, SizeOf(Tv));
    FillChar(Addr, SizeOf(Addr), 0);
    Addr.sin_family := AF_INET;
    Addr.sin_port := htons(ESPCAM_DISCOVERY_PORT);
    Addr.sin_addr.s_addr := LongWord($FFFFFFFF);
    Msg := 'ESPCAM_DISCOVER';

    Inicio := GetTickCount64;
    UltimoEnvio := 0;
    UltimoSite := GetTickCount64;
    while GetTickCount64 - Inicio < QWord(ATimeoutMs) do
    begin
      if FCancel then
      begin
        Fail('Cancelado pelo usuario.');
        Exit;
      end;
      if GetTickCount64 - UltimoEnvio >= 2000 then
      begin
        UltimoEnvio := GetTickCount64;
        fpSendTo(S, PChar(Msg), Length(Msg), 0, @Addr, SizeOf(Addr));
        Stage(Format('Procurando a camera %s na rede da casa (broadcast UDP %d)... %ds de %ds',
          [ADeviceId, ESPCAM_DISCOVERY_PORT, (GetTickCount64 - Inicio) div 1000, ATimeoutMs div 1000]));
      end;

      FromLen := SizeOf(From);
      N := fpRecvFrom(S, @Buf, SizeOf(Buf) - 1, 0, @From, @FromLen);
      if N > 0 then
      begin
        Buf[N] := #0;
        Resp := StrPas(Buf);
        Log('[UDP] ' + NetAddrToStr(From.sin_addr) + ' -> ' + Resp);
        try
          J := GetJSON(Resp);
          try
            if J is TJSONObject then
            begin
              O := TJSONObject(J);
              FoundId := O.Get('device_id', '');
              if (ADeviceId = '') or SameText(FoundId, ADeviceId) then
              begin
                Ip := O.Get('ip', NetAddrToStr(From.sin_addr));
                Porta := O.Get('porta', 80);
                PortaStream := O.Get('porta_stream', Porta);
                Exit(True);
              end;
            end;
          finally
            J.Free;
          end;
        except
          // resposta que nao e JSON: ignora
        end;
      end;

      // Plano B: a camera manda heartbeat com o IP para o site
      if (ASiteBaseUrl <> '') and (AApiKey <> '') and (GetTickCount64 - UltimoSite >= 15000) then
      begin
        UltimoSite := GetTickCount64;
        if HttpRequest('GET', ASiteBaseUrl + '/api/camera_discovery.php?acao=listar', '', AApiKey, 8000, Code, Resp)
          and (Code = 200) then
        try
          J := GetJSON(Resp);
          try
            Arr := TJSONObject(J).Get('cameras', TJSONArray(nil));
            if Assigned(Arr) then
              for I := 0 to Arr.Count - 1 do
              begin
                O := TJSONObject(Arr.Items[I]);
                if SameText(O.Get('device_id', ''), ADeviceId) and O.Get('is_online', False)
                  and (O.Get('ip_address', '') <> '') then
                begin
                  Ip := O.Get('ip_address', '');
                  Log('Camera encontrada pelo heartbeat no site: ' + Ip);
                  Exit(True);
                end;
              end;
          finally
            J.Free;
          end;
        except
        end;
      end;
      if Assigned(FOnIdle) then FOnIdle(Self);
    end;
    Fail('A camera ' + ADeviceId + ' nao apareceu na rede da casa em ' + IntToStr(ATimeoutMs div 1000) +
      's. Confira se as senhas das redes estao corretas: sem conseguir conectar, a camera reabre o espcam em 3 min.');
  finally
    CloseSocket(S);
  end;
end;

function TEspCamProvisioner.RegisterOnSite(const ARegisterUrl, AApiKey, AToken: string): Boolean;
var
  O: TJSONObject;
  Code: Integer;
  Resp: string;
begin
  Result := False;
  O := TJSONObject.Create;
  try
    O.Add('acao', 'register_token');
    O.Add('device_id', DeviceId);
    O.Add('token', AToken);
    O.Add('mac', Mac);
    O.Add('ip', Ip);
    O.Add('tipo', 'espcam');
    O.Add('porta', Porta);
    O.Add('porta_stream', PortaStream);
    Stage(Format('Registrando a camera no site (tipo espcam, %s porta %d, stream %d)...', [Ip, Porta, PortaStream]));
    if not HttpRequest('POST', ARegisterUrl, O.AsJSON, AApiKey, 15000, Code, Resp) then
    begin
      if Pos('SSL', UpperCase(Resp)) > 0 then
        Resp := Resp + ' (faltam as DLLs do OpenSSL ao lado do exe?)';
      Fail('Falha ao falar com o site: ' + Resp);
      Exit;
    end;
    Log('Site respondeu HTTP ' + IntToStr(Code) + ': ' + Copy(Resp, 1, 300));
    if Code = 401 then
    begin
      if AApiKey <> '' then
      begin
        Log('Tentando registrar em modo pareamento direto (sem chave de API)...');
        if HttpRequest('POST', ARegisterUrl, O.AsJSON, '', 15000, Code, Resp) and (Code >= 200) and (Code < 300) then
        begin
          Log('Site registrou com sucesso em modo pareamento direto.');
          Result := True;
          Exit;
        end;
      end;
      Fail('O site recusou a chave de API (HTTP 401).');
    end
    else if (Code < 200) or (Code >= 300) then
      Fail('O site retornou HTTP ' + IntToStr(Code) + '.')
    else
      Result := True;
  finally
    O.Free;
  end;
end;

function TEspCamProvisioner.LoadHouseWifisFromSite(const ASiteBaseUrl, AApiKey: string; ADest: TStrings): Boolean;
var
  Code, I: Integer;
  Resp: string;
  J: TJSONData;
  Arr: TJSONArray;
  W: TJSONObject;
begin
  Result := False;
  if AApiKey = '' then
  begin
    Fail('Informe a Chave API para carregar as redes do site.');
    Exit;
  end;
  if not HttpRequest('GET', ASiteBaseUrl + '/api/camera_discovery.php?acao=get_house_wifis', '', AApiKey, 15000, Code, Resp)
    or (Code <> 200) then
  begin
    Fail('Falha ao carregar redes do site (HTTP ' + IntToStr(Code) + '): ' + Copy(Resp, 1, 200));
    Exit;
  end;
  try
    J := GetJSON(Resp);
  except
    Fail('Resposta invalida do site: ' + Copy(Resp, 1, 200));
    Exit;
  end;
  try
    Arr := TJSONObject(J).Get('wifis', TJSONArray(nil));
    if not Assigned(Arr) or (Arr.Count = 0) then
    begin
      Fail('O site nao tem redes Wi-Fi da casa cadastradas.');
      Exit;
    end;
    ADest.Clear;
    for I := 0 to Arr.Count - 1 do
    begin
      W := TJSONObject(Arr.Items[I]);
      ADest.Add(W.Get('ssid', '') + ';' + W.Get('senha', ''));
    end;
    Log(Format('%d rede(s) carregada(s) do site.', [Arr.Count]));
    Result := True;
  finally
    J.Free;
  end;
end;

end.

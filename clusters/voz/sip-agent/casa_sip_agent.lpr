program casa_sip_agent;

{$mode objfpc}{$H+}

uses
  {$IFDEF UNIX}cthreads, BaseUnix, UnixType,{$ENDIF}
  Classes, SysUtils, IniFiles, aibase, aisip_types, aisip_server,
  aisipvoiceagent, aisipvoicehttp;

const Version = '1.0.0';
var Stopping: Boolean = False;

function Env(const Key, Default: string): string;
begin
  Result := GetEnvironmentVariable(Key);
  if Result = '' then Result := Default;
end;

function EnvInt(const Key: string; Default, Min, Max: Integer): Integer;
begin
  if not TryStrToInt(Env(Key, IntToStr(Default)), Result) or
    (Result < Min) or (Result > Max) then
    raise Exception.CreateFmt('%s must be %d..%d', [Key, Min, Max]);
end;

{$IFDEF UNIX}
procedure SignalStop(Signal: cint); cdecl;
begin Stopping := True; end;
{$ENDIF}

type
  TApplication = class
  private
    Server: TAISIPServer;
    Backend: TAISIPVoiceHTTPBackend;
    Agent: TAISIPVoiceAgent;
    Users: TMemIniFile;
    Extension: string;
    procedure Credential(Sender: TObject; const UserName, Realm, Remote: string;
      var Secret: string; var Kind: TAISIPCredentialKind; var Found: Boolean);
    procedure AllowRegistration(Sender: TObject; const UserName, Target,
      Remote: string; var Allow: Boolean);
    procedure AllowCall(Sender: TObject; const UserName, Target,
      Remote: string; var Allow: Boolean);
    procedure Route(Sender: TObject; CallID: TAISIPCallID; const Caller,
      Target: string; var Destination: string; var Action: TAISIPRouteAction);
    procedure SIPError(Sender: TObject; Code: Integer; const Message: string);
    procedure VoiceError(Sender: TObject; CallID: TAISIPCallID; const Text: string);
    procedure Started(Sender: TObject; CallID: TAISIPCallID);
    procedure Ended(Sender: TObject; CallID: TAISIPCallID);
    procedure Responded(Sender: TObject; CallID: TAISIPCallID; const Text: string);
    function HasUser(const UserName: string): Boolean;
  public
    constructor Create;
    destructor Destroy; override;
    procedure Run(CheckOnly: Boolean);
  end;

procedure Log(const Message: string);
begin
  WriteLn(FormatDateTime('yyyy-mm-dd hh:nn:ss', Now), ' [casa-sip-agent] ', Message);
  Flush(Output);
end;

constructor TApplication.Create;
var Path, Mode: string; Names: TStringList; I, ValidUsers: Integer;
begin
  inherited Create;
  Server := TAISIPServer.Create(nil);
  Backend := TAISIPVoiceHTTPBackend.Create(nil);
  Agent := TAISIPVoiceAgent.Create(nil);
  Extension := Env('CASA_SIP_EXTENSION', '8000');
  if (Extension = '') or (Length(Extension) > 32) then
    raise Exception.Create('CASA_SIP_EXTENSION must contain 1..32 digits');
  for I := 1 to Length(Extension) do
    if not (Extension[I] in ['0'..'9']) then
      raise Exception.Create('CASA_SIP_EXTENSION must contain digits');
  Server.BindAddress := Env('CASA_SIP_BIND', '0.0.0.0');
  Server.PublicAddress := Env('CASA_SIP_PUBLIC_ADDRESS', '');
  Server.Realm := Env('CASA_SIP_REALM', 'casa.local');
  Server.SIPPort := EnvInt('CASA_SIP_PORT', 5060, 1024, 65535);
  Server.RTPPort := EnvInt('CASA_SIP_RTP_PORT', 42000, 1024, 64000);
  Server.RuntimeLibrary := Env('CASA_SIP_LIBRARY', '/opt/casa/servicos/sip-agent/libchatgpt_sip_bridge.so');
  if not FileExists(Server.RuntimeLibrary) then
    raise Exception.Create('CASA_SIP_LIBRARY does not point to the compiled SIP runtime');
  Mode := LowerCase(Env('CASA_SIP_DIGEST', 'sha256'));
  if Mode = 'md5' then Server.DigestAlgorithm := sdMD5
  else if Mode <> 'sha256' then raise Exception.Create('CASA_SIP_DIGEST must be sha256 or md5');
  Path := Env('CASA_SIP_USERS_FILE', '/etc/casa/sip-users.ini');
  if not FileExists(Path) then raise Exception.Create('Configure CASA_SIP_USERS_FILE with SIP accounts');
  Users := TMemIniFile.Create(Path);
  Names := TStringList.Create;
  try
    ValidUsers := 0; Users.ReadSections(Names);
    for I := 0 to Names.Count-1 do if HasUser(Names[I]) then Inc(ValidUsers);
    if ValidUsers = 0 then raise Exception.Create('At least one SIP account with a real password is required');
  finally Names.Free; end;
  Server.OnAuthenticate := @Credential;
  Server.OnAuthorizeRegistration := @AllowRegistration;
  Server.OnAuthorizeCall := @AllowCall; Server.OnRouteCall := @Route;
  Server.OnError := @SIPError;
  Backend.STTURL := Env('CASA_SIP_STT_URL', Backend.STTURL);
  Backend.STTToken := Env('CASA_SIP_STT_TOKEN', '');
  Backend.TTSURL := Env('CASA_SIP_TTS_URL', Backend.TTSURL);
  Backend.AudioBaseURL := Env('CASA_SIP_AUDIO_BASE_URL', Backend.AudioBaseURL);
  Backend.TTSToken := Env('CASA_SIP_TTS_TOKEN', '');
  Backend.Speaker := Env('CASA_SIP_SPEAKER', 'padrao');
  Backend.ReplyURL := Env('CASA_SIP_AI_URL', Backend.ReplyURL);
  Backend.ReplyToken := Env('CASA_SIP_AI_TOKEN', Env('CASA_SYSTEM_API_TOKEN', ''));
  Backend.TimeoutMs := EnvInt('CASA_SIP_HTTP_TIMEOUT_MS', 30000, 100, 120000);
  Mode := LowerCase(Env('CASA_SIP_AI_FORMAT', 'casa'));
  if Mode = 'openai-compatible' then
  begin
    Backend.ReplyFormat := vrOpenAICompatible;
    Backend.Model := Env('CASA_SIP_AI_MODEL', '');
    Backend.SystemPrompt := Env('CASA_SIP_SYSTEM_PROMPT', Backend.SystemPrompt);
    if Backend.Model = '' then raise Exception.Create('CASA_SIP_AI_MODEL is required for openai-compatible');
  end
  else if Mode = 'casa' then
  begin
    if Backend.ReplyToken = '' then raise Exception.Create('Configure CASA_SIP_AI_TOKEN or CASA_SYSTEM_API_TOKEN');
  end
  else raise Exception.Create('CASA_SIP_AI_FORMAT must be casa or openai-compatible');
  Agent.SIP := Server; Agent.Backend := Backend;
  Agent.MaxCalls := EnvInt('CASA_SIP_MAX_CALLS', 4, 1, 16);
  Agent.SilenceMs := EnvInt('CASA_SIP_SILENCE_MS', 700, 100, 5000);
  Agent.MinSpeechMs := EnvInt('CASA_SIP_MIN_SPEECH_MS', 200, 20, 10000);
  Agent.MaxUtteranceMs := EnvInt('CASA_SIP_MAX_UTTERANCE_MS', 15000, 1000, 30000);
  Agent.EnergyThreshold := EnvInt('CASA_SIP_ENERGY_THRESHOLD', 500, 1, 32767);
  Agent.OnError := @VoiceError; Agent.OnSessionStarted := @Started;
  Agent.OnSessionEnded := @Ended; Agent.OnResponse := @Responded;
end;

destructor TApplication.Destroy;
begin
  { Calls and workers must stop before releasing the backend. }
  if Server <> nil then Server.Stop;
  Agent.Free; Server.Free; Backend.Free; Users.Free;
  inherited Destroy;
end;

function TApplication.HasUser(const UserName: string): Boolean;
var Password: string;
begin
  Password := Users.ReadString(UserName, 'password', '');
  Result := Users.SectionExists(UserName) and (Length(Password) >= 8) and
    (Pos('CHANGE_ME', UpperCase(Password)) = 0);
end;

procedure TApplication.Credential(Sender: TObject; const UserName, Realm,
  Remote: string; var Secret: string; var Kind: TAISIPCredentialKind; var Found: Boolean);
begin
  Found := (Realm = Server.Realm) and HasUser(UserName);
  Kind := skPassword; Secret := '';
  if Found then Secret := Users.ReadString(UserName, 'password', '');
end;

procedure TApplication.AllowRegistration(Sender: TObject; const UserName,
  Target, Remote: string; var Allow: Boolean);
begin Allow := HasUser(UserName); end;

procedure TApplication.AllowCall(Sender: TObject; const UserName,
  Target, Remote: string; var Allow: Boolean);
begin Allow := HasUser(UserName) and (Target = Extension); end;

procedure TApplication.Route(Sender: TObject; CallID: TAISIPCallID;
  const Caller, Target: string; var Destination: string; var Action: TAISIPRouteAction);
begin
  Destination := ''; Action := srDeny;
  if HasUser(Caller) and (Target = Extension) then Action := srAnswerLocally;
end;

procedure TApplication.SIPError(Sender: TObject; Code: Integer; const Message: string);
begin if not Stopping then Log(Format('SIP error %d: %s', [Code, Message])); end;

procedure TApplication.VoiceError(Sender: TObject; CallID: TAISIPCallID; const Text: string);
begin Log(Format('Call %d processing unavailable: %s', [CallID, Text])); end;

procedure TApplication.Started(Sender: TObject; CallID: TAISIPCallID);
begin Log(Format('Call %d started', [CallID])); end;

procedure TApplication.Ended(Sender: TObject; CallID: TAISIPCallID);
begin Log(Format('Call %d ended', [CallID])); end;

procedure TApplication.Responded(Sender: TObject; CallID: TAISIPCallID; const Text: string);
begin Log(Format('Call %d response ready (%d bytes of text)', [CallID, Length(Text)])); end;

procedure TApplication.Run(CheckOnly: Boolean);
begin
  Agent.Start;
  if CheckOnly then
  begin Log('Configuration valid; services and inference have not been contacted'); Exit; end;
  if not Server.Start then raise Exception.Create(Server.LastError);
  Log(Format('Version %s listening on SIP %d, AI extension %s', [Version, Server.LocalPort, Extension]));
  while not Stopping do
    if not Agent.Poll(10) and not Stopping then
      raise Exception.Create('SIP polling failed: '+Server.LastError);
  Server.Stop; Agent.Stop; Log('Stopped');
end;

var App: TApplication;
begin
  if (ParamCount = 1) and (ParamStr(1) = '--version') then
  begin WriteLn(Version); Halt(0); end;
  if (ParamCount > 1) or ((ParamCount = 1) and (ParamStr(1) <> '--check-config')) then
  begin WriteLn('Usage: casa-sip-agent [--version|--check-config]'); Halt(2); end;
  {$IFDEF UNIX}
  fpSignal(SIGTERM, @SignalStop); fpSignal(SIGINT, @SignalStop);
  {$ENDIF}
  try
    App := TApplication.Create;
    try App.Run(ParamCount = 1); finally App.Free; end;
  except
    on E: Exception do begin Log('Fatal: '+E.Message); Halt(1); end;
  end;
end.

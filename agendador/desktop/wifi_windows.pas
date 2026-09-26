unit wifi_windows;
{$mode objfpc}{$H+}{$packrecords c}
interface
uses Classes,SysUtils,Windows,Math;
type
 TWifiSession=class
 private
  Handle:THandle; Guid:TGUID; Previous,Temporary:UnicodeString;
  function CurrentProfile:UnicodeString;
  procedure ConnectProfile(const Profile:UnicodeString);
 public
  constructor Create;
  destructor Destroy;override;
  procedure JoinSetup(const SSID,Password:string);
  procedure Restore;
 end;
function ProtectSecret(const S:string):string;
function UnprotectSecret(const S:string):string;
implementation
type
 TBlob=record Len:DWORD;Data:PByte;end;
 TInterfaceInfo=record Guid:TGUID; Description:array[0..255] of WideChar; State:DWORD;end;
 PInterfaceList=^TInterfaceList;
 TInterfaceList=record Count,Index:DWORD;Items:array[0..63] of TInterfaceInfo;end;
 PConnection=^TConnection;
 TConnection=record State,Mode:DWORD;Profile:array[0..255] of WideChar;end;
 TParameters=record Mode:DWORD;Profile:PWideChar;SSID,Desired:Pointer;BssType,Flags:DWORD;end;
function WlanOpenHandle(V:DWORD;R:Pointer;out N:DWORD;out H:THandle):DWORD;stdcall;external 'wlanapi.dll';
function WlanCloseHandle(H:THandle;R:Pointer):DWORD;stdcall;external 'wlanapi.dll';
function WlanEnumInterfaces(H:THandle;R:Pointer;out L:PInterfaceList):DWORD;stdcall;external 'wlanapi.dll';
procedure WlanFreeMemory(P:Pointer);stdcall;external 'wlanapi.dll';
function WlanQueryInterface(H:THandle;const G:TGUID;Op:DWORD;R:Pointer;out Size:DWORD;out P:Pointer;T:Pointer):DWORD;stdcall;external 'wlanapi.dll';
function WlanSetProfile(H:THandle;const G:TGUID;Flags:DWORD;XML,Security:PWideChar;Overwrite:BOOL;R:Pointer;out Reason:DWORD):DWORD;stdcall;external 'wlanapi.dll';
function WlanConnect(H:THandle;const G:TGUID;const Params:TParameters;R:Pointer):DWORD;stdcall;external 'wlanapi.dll';
function WlanDisconnect(H:THandle;const G:TGUID;R:Pointer):DWORD;stdcall;external 'wlanapi.dll';
function WlanDeleteProfile(H:THandle;const G:TGUID;Name:PWideChar;R:Pointer):DWORD;stdcall;external 'wlanapi.dll';
function CryptProtectData(const InData:TBlob;Descr:PWideChar;Entropy,Reserved,Prompt:Pointer;Flags:DWORD;out OutData:TBlob):BOOL;stdcall;external 'crypt32.dll';
function CryptUnprotectData(const InData:TBlob;Descr:Pointer;Entropy,Reserved,Prompt:Pointer;Flags:DWORD;out OutData:TBlob):BOOL;stdcall;external 'crypt32.dll';
function ProtectSecret(const S:string):string;
var B,O:TBlob;I:Integer;
begin
 Result:='';if S='' then Exit;B.Len:=Length(S);B.Data:=PByte(PChar(S));
 if not CryptProtectData(B,nil,nil,nil,nil,1,O) then RaiseLastOSError;
 try for I:=0 to O.Len-1 do Result:=Result+IntToHex(O.Data[I],2);finally LocalFree(HLOCAL(O.Data));end;
end;
function UnprotectSecret(const S:string):string;
var Raw:string;B,O:TBlob;I:Integer;
begin
 Result:='';if S='' then Exit;if Odd(Length(S)) then raise Exception.Create('Credencial corrompida');
 SetLength(Raw,Length(S) div 2);for I:=1 to Length(Raw) do Raw[I]:=Chr(StrToInt('$'+Copy(S,I*2-1,2)));
 B.Len:=Length(Raw);B.Data:=PByte(PChar(Raw));
 if not CryptUnprotectData(B,nil,nil,nil,nil,1,O) then raise Exception.Create('Credencial pertence a outro usuario Windows ou esta corrompida');
 try SetString(Result,PChar(O.Data),O.Len);finally LocalFree(HLOCAL(O.Data));end;
end;
procedure Check(Code:DWORD);
begin if Code<>0 then raise Exception.CreateFmt('Wi-Fi Windows: %s (%d). Verifique o adaptador, o servico WLAN e a permissao de localizacao.',[SysErrorMessage(Code),Code]);end;
constructor TWifiSession.Create;
var V:DWORD;L:PInterfaceList;I,Chosen:Integer;
begin
 inherited Create;Handle:=0;Check(WlanOpenHandle(2,nil,V,Handle));
 Check(WlanEnumInterfaces(Handle,nil,L));
 try
  if L^.Count=0 then raise Exception.Create('Nenhum adaptador Wi-Fi disponivel');
  Chosen:=0;for I:=0 to Min(Integer(L^.Count),64)-1 do if L^.Items[I].State=1 then begin Chosen:=I;Break;end;
  Guid:=L^.Items[Chosen].Guid;
  if L^.Items[Chosen].State=1 then Previous:=CurrentProfile else Previous:='';
 finally WlanFreeMemory(L);end;
end;
function TWifiSession.CurrentProfile:UnicodeString;
var P:Pointer;Size,Code:DWORD;
begin
 Result:='';Code:=WlanQueryInterface(Handle,Guid,7,nil,Size,P,nil);
 if Code=5023 then Exit;Check(Code);
 try Result:=UnicodeString(PWideChar(@PConnection(P)^.Profile[0]));finally WlanFreeMemory(P);end;
end;
procedure TWifiSession.ConnectProfile(const Profile:UnicodeString);
var P:TParameters;I:Integer;
begin
 FillChar(P,SizeOf(P),0);P.Profile:=PWideChar(Profile);P.BssType:=1;
 Check(WlanConnect(Handle,Guid,P,nil));
 for I:=1 to 60 do begin Sleep(500);if CurrentProfile=Profile then Exit;end;
 raise Exception.Create('Nao foi possivel conectar a rede Wi-Fi em 30 segundos');
end;
function X(const S:string):string;
begin Result:=StringReplace(S,'&','&amp;',[rfReplaceAll]);Result:=StringReplace(Result,'<','&lt;',[rfReplaceAll]);Result:=StringReplace(Result,'>','&gt;',[rfReplaceAll]);Result:=StringReplace(Result,'"','&quot;',[rfReplaceAll]);end;
procedure TWifiSession.JoinSetup(const SSID,Password:string);
var XML:UnicodeString;Reason:DWORD;I:Integer;Hex:string;
begin
 if (Length(SSID)<1) or (Length(SSID)>32) or (Length(Password)<8) or (Length(Password)>63) then raise Exception.Create('SSID ou senha do equipamento invalidos');
 Temporary:='Agendador-'+UTF8Decode(IntToHex(GetTickCount64,16));Hex:='';for I:=1 to Length(SSID) do Hex:=Hex+IntToHex(Ord(SSID[I]),2);
 XML:=UTF8Decode('<?xml version="1.0"?><WLANProfile xmlns="http://www.microsoft.com/networking/WLAN/profile/v1"><name>'+UTF8Encode(Temporary)+'</name><SSIDConfig><SSID><hex>'+Hex+'</hex></SSID></SSIDConfig><connectionType>ESS</connectionType><connectionMode>manual</connectionMode><MSM><security><authEncryption><authentication>WPA2PSK</authentication><encryption>AES</encryption><useOneX>false</useOneX></authEncryption><sharedKey><keyType>passPhrase</keyType><protected>false</protected><keyMaterial>'+X(Password)+'</keyMaterial></sharedKey></security></MSM></WLANProfile>');
 Check(WlanSetProfile(Handle,Guid,2,PWideChar(XML),nil,False,nil,Reason));
 ConnectProfile(Temporary);
end;
procedure TWifiSession.Restore;
begin
 if Temporary='' then Exit;
 try if Previous<>'' then ConnectProfile(Previous) else Check(WlanDisconnect(Handle,Guid,nil));
 finally WlanDeleteProfile(Handle,Guid,PWideChar(Temporary),nil);Temporary:='';end;
end;
destructor TWifiSession.Destroy;
begin if Handle<>0 then WlanCloseHandle(Handle,nil);inherited Destroy;end;
end.

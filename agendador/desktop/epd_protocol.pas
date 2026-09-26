unit epd_protocol;
{$mode objfpc}{$H+}
interface
uses Classes, SysUtils, fpjson, jsonparser;
function Request(const IP: string; const Payload: TJSONObject; Timeout: Integer=2500): TJSONObject;
function NewToken: string;
function NormalizeLine(const S: string): string;
procedure ValidateMessage(const Lines: array of string; const QR: string);
function ValidIPv4(const S: string): Boolean;
function NetworkPrefix: string;
implementation
uses Windows, WinSock2;
function NewToken: string;
var G:TGUID;
begin CreateGUID(G);Result:=LowerCase(GUIDToString(G));Result:=StringReplace(Result,'-','',[rfReplaceAll]);Delete(Result,1,1);Delete(Result,Length(Result),1);end;
function ValidIPv4(const S:string):Boolean;
var A:TStringArray; V,I:Integer;
begin
 Result:=False; A:=S.Split('.');if Length(A)<>4 then Exit;
 for I:=0 to 3 do if not TryStrToInt(A[I],V) or (V<0) or (V>255) then Exit;
 Result:=True;
end;
function NetworkPrefix:string;
var D:TWSAData; H:array[0..255] of char; P:PHostEnt; A:in_addr;
begin
 Result:='192.168.1';if WSAStartup($0202,D)<>0 then Exit;
 try
   if gethostname(@H[0],SizeOf(H))=0 then begin
     P:=gethostbyname(@H[0]);
     if (P<>nil) and (P^.h_addr_list<>nil) and (P^.h_addr_list^<>nil) then begin
       Move(P^.h_addr_list^^,A,SizeOf(A));Result:=StrPas(inet_ntoa(A));
       Delete(Result,LastDelimiter('.',Result),MaxInt);
     end;
   end;
 finally WSACleanup;end;
end;
function Request(const IP:string; const Payload:TJSONObject;Timeout:Integer):TJSONObject;
var D:TWSAData; S:TSocket; Addr:TSockAddrIn; Mode:u_long; F,E:TFDSet;
 TV:TTimeVal; Code,Len,N,Offset:LongInt; Wire,Reply:string; Buf:array[0..1023] of char;
 J:TJSONData; Deadline:QWord;
begin
 Result:=nil;if not ValidIPv4(IP) then raise Exception.Create('IP invalido: '+IP);
 if WSAStartup($0202,D)<>0 then raise Exception.Create('Falha no Winsock');
 S:=INVALID_SOCKET;
 try
  S:=socket(AF_INET,SOCK_STREAM,IPPROTO_TCP);if S=INVALID_SOCKET then raise Exception.Create('Falha no socket');
  Mode:=1;ioctlsocket(S,LongInt(FIONBIO),Mode);
  FillChar(Addr,SizeOf(Addr),0);Addr.sin_family:=AF_INET;Addr.sin_port:=htons(8090);Addr.sin_addr.S_addr:=inet_addr(PChar(IP));
  Code:=connect(S,Addr,SizeOf(Addr));
  if Code<>0 then begin
   if WSAGetLastError<>WSAEWOULDBLOCK then raise Exception.Create('Equipamento indisponivel');
   FD_ZERO(F);FD_SET(S,F);FD_ZERO(E);FD_SET(S,E);TV.tv_sec:=Timeout div 1000;TV.tv_usec:=(Timeout mod 1000)*1000;
   if (select(0,nil,@F,@E,@TV)<=0) or FD_ISSET(S,E) then raise Exception.Create('Tempo de conexao excedido');
   Code:=0;Len:=SizeOf(Code);getsockopt(S,SOL_SOCKET,SO_ERROR,@Code,Len);
   if Code<>0 then raise Exception.Create('Conexao recusada');
  end;
  Mode:=0;ioctlsocket(S,LongInt(FIONBIO),Mode);
  setsockopt(S,SOL_SOCKET,SO_RCVTIMEO,@Timeout,SizeOf(Timeout));
  setsockopt(S,SOL_SOCKET,SO_SNDTIMEO,@Timeout,SizeOf(Timeout));
  Wire:=Payload.AsJSON+#10;Offset:=1;
  while Offset<=Length(Wire) do begin N:=send(S,@Wire[Offset],Length(Wire)-Offset+1,0);if N<=0 then raise Exception.Create('Falha no envio');Inc(Offset,N);end;
  Reply:='';Deadline:=GetTickCount64+QWord(Timeout);
  repeat
   N:=recv(S,@Buf[0],SizeOf(Buf),0);if N<=0 then raise Exception.Create('Sem resposta do equipamento');
   SetString(Wire,PChar(@Buf[0]),N);Reply:=Reply+Wire;
   if Length(Reply)>8192 then raise Exception.Create('Resposta excedeu o limite');
   if GetTickCount64>Deadline then raise Exception.Create('Tempo de resposta excedido');
  until Pos(#10,Reply)>0;
  J:=GetJSON(Copy(Reply,1,Pos(#10,Reply)-1));
  if not (J is TJSONObject) then begin J.Free;raise Exception.Create('Resposta invalida');end;
  Result:=TJSONObject(J);
  if not Result.Get('ok',False) then begin Wire:=Result.Get('error','Erro no equipamento');FreeAndNil(Result);raise Exception.Create(Wire);end;
 finally if S<>INVALID_SOCKET then closesocket(S);WSACleanup;end;
end;
function NormalizeLine(const S:string):string;
const A:array[0..45] of string=('á','à','â','ã','ä','é','ê','è','ë','í','ì','î','ï','ó','ô','õ','ò','ö','ú','ù','û','ü','ç','Á','À','Â','Ã','Ä','É','Ê','È','Ë','Í','Ì','Î','Ï','Ó','Ô','Õ','Ò','Ö','Ú','Ù','Û','Ü','Ç');
 B:array[0..45] of string=('a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','A','A','A','A','A','E','E','E','E','I','I','I','I','O','O','O','O','O','U','U','U','U','C');
var I:Integer;
begin Result:=S;for I:=0 to High(A) do Result:=StringReplace(Result,A[I],B[I],[rfReplaceAll]);end;
procedure ValidateMessage(const Lines:array of string;const QR:string);
var I,K:Integer;S:string;
begin
 if Length(Lines)<>4 then raise Exception.Create('Informe quatro linhas');
 for I:=0 to 3 do begin S:=NormalizeLine(Lines[I]);if Length(S)>26 then raise Exception.CreateFmt('Linha %d: maximo 26 caracteres',[I+1]);
 for K:=1 to Length(S) do if (Ord(S[K])<32) or (Ord(S[K])>126) then raise Exception.Create('Texto contem caractere nao suportado');end;
 if Length(QR)>53 then raise Exception.Create('QR Code: maximo 53 bytes UTF-8');
end;
end.

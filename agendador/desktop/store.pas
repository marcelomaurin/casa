unit store;
{$mode objfpc}{$H+}
interface
uses Classes,SysUtils,sqlite3conn,sqldb,db,fpjson;
type
 TStore=class
 private C:TSQLite3Connection;T:TSQLTransaction;
 public
  constructor Create(const FileName:string);
  destructor Destroy;override;
  procedure Exec(const SQL:string);
  function Rows(const SQL:string):TJSONArray;
  function Row(const SQL:string):TJSONObject;
  function LastID:Int64;
  function ClaimDue(const AtTime:string):TJSONObject;
  procedure CompleteSchedule(ID:Integer;Success:Boolean;const ErrorText,RetryTime:string);
 end;
function Q(const S:string):string;
implementation
function Q(const S:string):string;begin Result:=QuotedStr(S);end;
constructor TStore.Create(const FileName:string);
begin
 inherited Create;ForceDirectories(ExtractFileDir(FileName));
 C:=TSQLite3Connection.Create(nil);T:=TSQLTransaction.Create(nil);C.Transaction:=T;T.Database:=C;
 C.DatabaseName:=FileName;C.Open;
 Exec('CREATE TABLE IF NOT EXISTS devices (id INTEGER PRIMARY KEY, hardware_id TEXT UNIQUE, name TEXT NOT NULL, ip TEXT NOT NULL DEFAULT '''', ssid TEXT NOT NULL DEFAULT '''', password TEXT NOT NULL DEFAULT '''', token TEXT NOT NULL DEFAULT '''', ap_ssid TEXT NOT NULL DEFAULT '''', ap_password TEXT NOT NULL DEFAULT '''', online INTEGER NOT NULL DEFAULT 0, last_seen TEXT NOT NULL DEFAULT '''')');
 Exec('CREATE TABLE IF NOT EXISTS messages (id INTEGER PRIMARY KEY, title TEXT NOT NULL, line1 TEXT NOT NULL, line2 TEXT NOT NULL, line3 TEXT NOT NULL, line4 TEXT NOT NULL, qr TEXT NOT NULL)');
 Exec('CREATE TABLE IF NOT EXISTS schedules (id INTEGER PRIMARY KEY, device_id INTEGER NOT NULL, message_id INTEGER NOT NULL, due TEXT NOT NULL, status TEXT NOT NULL DEFAULT ''pending'', attempts INTEGER NOT NULL DEFAULT 0, request_id TEXT NOT NULL, payload TEXT NOT NULL, error TEXT NOT NULL DEFAULT '''')');
 Exec('CREATE INDEX IF NOT EXISTS schedules_due ON schedules(status,due)');
 Exec('CREATE TABLE IF NOT EXISTS history (id INTEGER PRIMARY KEY, at TEXT NOT NULL, device_id INTEGER, message TEXT NOT NULL)');
 Exec('UPDATE schedules SET status=''pending'' WHERE status=''sending''');
 Exec('UPDATE devices SET online=0');
end;
procedure TStore.Exec(const SQL:string);
begin
 try if not T.Active then T.StartTransaction;C.ExecuteDirect(SQL);T.Commit;
 except if T.Active then T.Rollback;raise;end;
end;
function TStore.Rows(const SQL:string):TJSONArray;
var Query:TSQLQuery;O:TJSONObject;I:Integer;
begin
 Result:=TJSONArray.Create;Query:=TSQLQuery.Create(nil);
 try
  try
   Query.Database:=C;Query.Transaction:=T;Query.SQL.Text:=SQL;Query.Open;
   while not Query.EOF do begin
    O:=TJSONObject.Create;for I:=0 to Query.FieldCount-1 do O.Add(Query.Fields[I].FieldName,Query.Fields[I].AsString);
    Result.Add(O);Query.Next;
   end;
   Query.Close;if T.Active then T.Commit;
  except Result.Free;Result:=nil;if T.Active then T.Rollback;raise;end;
 finally Query.Free;end;
end;
function TStore.Row(const SQL:string):TJSONObject;
var A:TJSONArray;
begin A:=Rows(SQL);try if A.Count=0 then Result:=nil else Result:=TJSONObject(A.Items[0].Clone);finally A.Free;end;end;
function TStore.LastID:Int64;
var O:TJSONObject;
begin O:=Row('SELECT last_insert_rowid() AS id');try Result:=StrToInt64(O.Get('id','0'));finally O.Free;end;end;
function TStore.ClaimDue(const AtTime:string):TJSONObject;
begin
 Result:=Row('SELECT * FROM schedules WHERE status=''pending'' AND due<='+Q(AtTime)+' ORDER BY due,id LIMIT 1');
 if Result<>nil then Exec('UPDATE schedules SET status=''sending'',attempts=attempts+1 WHERE id='+Result.Get('id','0'));
end;
procedure TStore.CompleteSchedule(ID:Integer;Success:Boolean;const ErrorText,RetryTime:string);
var S:TJSONObject;SQL:string;
begin
 if Success then begin Exec('UPDATE schedules SET status=''sent'',error='''' WHERE id='+IntToStr(ID));Exit;end;
 S:=Row('SELECT attempts FROM schedules WHERE id='+IntToStr(ID));if S=nil then Exit;
 try
  if StrToInt(S.Get('attempts','0'))>=5 then SQL:='status=''failed''' else SQL:='status=''pending'',due='+Q(RetryTime);
  Exec('UPDATE schedules SET '+SQL+',error='+Q(ErrorText)+' WHERE id='+IntToStr(ID));
 finally S.Free;end;
end;
destructor TStore.Destroy;
begin C.Close;T.Free;C.Free;inherited Destroy;end;
end.

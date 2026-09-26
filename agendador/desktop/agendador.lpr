program agendador;
{$mode objfpc}{$H+}
uses Interfaces,Forms,Dialogs,SysUtils,Classes,Windows,mainform,selftests;
var Instance:THandle;Report:TStringList;Frame:Integer;
begin
 RequireDerivedFormResource:=False;
 Application.Initialize;
 if (ParamStr(1)='--self-test') or (ParamStr(1)='--protocol-test') then begin
  try RunTests(ParamStr(2),ParamStr(1)='--protocol-test');Halt(0);
  except on E:Exception do begin
   Report:=TStringList.Create;Report.Add(E.Message);Report.Add(BackTraceStrFunc(ExceptAddr));
   for Frame:=0 to ExceptFrameCount-1 do Report.Add(BackTraceStrFunc(ExceptFrames[Frame]));
   Report.SaveToFile(IncludeTrailingPathDelimiter(ParamStr(2))+'result.txt');Report.Free;Halt(1);
  end;end;
 end;
 Instance:=CreateMutex(nil,True,'Local\FatecAgendadorDesktop');
 if (Instance=0) or (GetLastError=ERROR_ALREADY_EXISTS) then begin MessageDlg('O agendador já está aberto nesta sessão do Windows.',mtInformation,[mbOK],0);Halt(1);end;
 Application.Title:='Agendador FATEC';
 Application.CreateForm(TMainForm,MainWindow);
 Application.Run;
 CloseHandle(Instance);
end.

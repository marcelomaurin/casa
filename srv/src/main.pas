unit main;

{$mode objfpc}{$H+}

interface

uses
  Classes, SysUtils, Forms, Controls, Graphics, Dialogs, IdSimpleServer,
  IdUDPServer;

type

  { Tfrmmain }

  Tfrmmain = class(TForm)
    IdSimpleServer1: TIdSimpleServer;
    IdUDPServer1: TIdUDPServer;
    procedure FormCreate(Sender: TObject);
  private

  public

  end;

var
  frmmain: Tfrmmain;

implementation

{$R *.lfm}

{ Tfrmmain }

procedure Tfrmmain.FormCreate(Sender: TObject);
begin
  id
end;

end.


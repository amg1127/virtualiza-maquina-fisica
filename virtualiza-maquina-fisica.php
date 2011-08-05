#!/usr/bin/php -d open_basedir=/
<?php
$vmuuid = '133aa681-d582-4e9e-be44-cb07ac0e6be8';

function exibe ($msg) {
    static $line_is_blank = true;
    $next_line_is_blank = false;
    $prefixo = '[' . date ('Y-m-d H:i:s') . '] ';
    if (substr ($msg, -1) == "\n") {
        $msg = substr ($msg, 0, strlen ($msg) - 1);
        $next_line_is_blank = true;
    }
    $msgtransformada = preg_replace ("/\n/s", "\n" . $prefixo, $msg);
    if ($line_is_blank) {
        $msgtransformada = $prefixo . $msgtransformada;
    }
    if ($next_line_is_blank) {
        $msgtransformada .= "\n";
    }
    $line_is_blank = $next_line_is_blank;
    fwrite (STDERR, $msgtransformada);
}

function sai ($codsaida) {
    global $vmuuid, $tmpf;
    echo ("\n **** Pressione ENTER para continuar... ****\n");
    fgets (STDIN);
    passthru ("VBoxManage unregistervm " . escapeshellarg ($vmuuid) . " --delete");
    passthru ("rm -f " . escapeshellarg ($tmpf));
    exit ($codsaida);
}

function morre ($msg) {
    exibe ($msg . "\n");
    exibe (" **** O programa não foi executado com sucesso! ****\n");
    sai (1);
}

function chamavbox ($cmdline) {
    $cmdline = "VBoxManage -q " . $cmdline;
    passthru ($cmdline, $retvar);
    if ($retvar !== 0) {
        morre ("Falha ao executar comando: '" . $cmdline . "'!");
    }
}

function pergunta ($prompt, $opcoes) {
    $cntlinhas = count ($opcoes);
    if ($cntlinhas > 1) {
        while (1) {
            echo ("\n" . $prompt . "\n");
            for ($i = 0; $i < $cntlinhas; $i++) {
                echo ("  + " . ($i + 1) . ". ");
                if (is_array ($opcoes[$i])) {
                    echo (implode ("\t", $opcoes[$i]));
                } else {
                    echo ($opcoes[$i]);
                }
                echo ("\n");
            }
            echo ("Escolha uma opção e digite o número correspondente: ");
            $resp = fgets (STDIN);
            if ($resp !== false) {
                $resp = (int) $resp;
                if ($resp > 0 && $resp <= $cntlinhas) {
                    $resp--;
                    break;
                }
                echo ("Opção inválida!\n");
            } else {
                morre ("Falha ao ler STDIN!");
            }
        }
    } else if ($cntlinhas == 1) {
        $resp = 0;
    } else {
        morre ("Nenhuma opção para responder à questão: '" . $prompt . "'!");
    }
    echo ("\n");
    return ($resp);
}

date_default_timezone_set ('America/Sao_Paulo');
exibe (" ++++ Script do AMG1127 para criar automaticamente uma máquina virtual para o boot do sistema operacional físico. ++++\n");

$disp = getenv ('DISPLAY');
if (empty ($disp)) {
    morre ('Este script precisa do modo gráfico para funcionar!');
}
exibe ("Invocando 'sudo' para ganhar o poder do 'root'. É necessário...\n");
passthru ("sudo true", $retvar);
if ($retvar) {
    morre ("Chamada do 'sudo' falhou!");
}

$saida = array ();
exec ("ip link show", $saida, $retvar);
if ($retvar) {
    morre ("Falha ao executar comando 'ip link show'!");
}
$ifaces = array ();
$cntlinhas = count ($saida);
if ($cntlinhas % 2) {
    morre ("Saída do 'ip link show' deveria ter um numero par de linhas!");
}
for ($i = 0; $i < $cntlinhas; $i += 2) {
    if (preg_match ("/^\\d+:\\s+(\\w+):\\s+<([^>]+)>\\s+/", $saida[$i], $matches)) {
        $ifflags = explode (',', $matches[2]);
        if (in_array ('UP', $ifflags) && (! in_array ('LOOPBACK', $ifflags))) {
            if (preg_match ("/^\\s+link\\/\\w+\\s+([a-fA-F0-9][a-fA-F0-9]:[a-fA-F0-9][a-fA-F0-9]:[a-fA-F0-9][a-fA-F0-9]:[a-fA-F0-9][a-fA-F0-9]:[a-fA-F0-9][a-fA-F0-9]:[a-fA-F0-9][a-fA-F0-9])\\s+/", $saida[$i+1], $macmatches)) {
                $ifaces[] = array ($matches[1], $macmatches[1]);
            } else {
                morre ("Impossível determinar endereço MAC da placa '" . $matches[1] . "'!");
            }
        }
    } else {
        morre ("Linha #" . ($i + 1) . " do 'ip link show' não possui dados de interface de rede!");
    }
}

$resp = pergunta ("Qual interface de rede deverá ser conectada à máquina virtual?", $ifaces);

chamavbox ("createvm --name 'Sistema operacional original' --uuid " . escapeshellarg ($vmuuid) . " --basefolder /tmp --register");
chamavbox ("modifyvm " . escapeshellarg ($vmuuid) . " " .
                               "--memory 512 " .
                               "--vram 12 " .
                               "--acpi on " .
                               "--ioapic on " .
                               "--pae on " .
                               "--cpus 1 " .
                               "--rtcuseutc off " .
                               "--cpuhotplug off " .
                               "--vtxvpid on " .
                               "--hwvirtex on " .
                               "--hwvirtexexcl off " .
                               "--nestedpaging on " .
                               "--boot1 disk " .
                               "--boot2 none " .
                               "--boot3 none " .
                               "--boot4 none " .
                               "--firmware bios " .
                               "--nic1 null " .
                               "--nic2 bridged " .
                               "--bridgeadapter2 " . escapeshellarg ($ifaces[$resp][0]) . " " .
                               "--macaddress1 " . str_replace (':', '', $ifaces[$resp][1]) . " " .
                               "--macaddress2 auto " .
                               "--uart1 off " .
                               "--uart2 off " .
                               "--audio alsa " .
                               "--clipboard disabled " .
                               "--usb off " .
                               "--usbehci off " .
                               "--vrde off " .
                               "--mouse usbtablet " .
                               "--audiocontroller hda");

exibe ("Se o sistema operacional original da estação de trabalho for alguma versão do Windows, ajustes devem ser feitos:\n");
exibe ("\t1. A aplicação 'MergeIDE' deve ser executada.\n");
exibe ("\t2. Um novo perfil de hardware deve ser criado.\n\n");

if (pergunta ("Esses ajustes já foram realizados na estação?", array ("Não", "Sim")) == 0) {
    morre ("Finalizando script, pois sistema operacional não está pronto!");
}

exibe ("Obtendo informações do 'dmidecode'...\n");
$titles = array ('/^BIOS Information/', '/^System Information/');
$needed = array (
    0 => array ('Vendor', 'Version', 'Release Date', 'BIOS Revision', 'Firmware Revision'),
    1 => array ('Manufacturer', 'Product Name', 'Version', 'Serial Number', 'UUID', 'Family')
);
$found = array ();
$smbiosmajor = "";
$smbiosminor = "";
for ($c = 0; $c < 2; $c++) {
    $saida = array ();
    exec ("sudo dmidecode -t" . $c, $saida, $retvar);
    if ($retvar) {
        morre ("Chamada para 'dmidecode -t" . $c . "' falhou!");
    }
    $found[$c] = array ();
    $cntlinhas = count ($saida);
    for ($i = 0; $i < $cntlinhas; $i++) {
        if (empty ($smbiosmajor) || empty ($smbiosminor)) {
            if (preg_match ("/^SMBIOS (\\d+)\\.(\\d+) present/", $saida[$i], $matches)) {
                $smbiosmajor = $matches[1];
                $smbiosminor = $matches[2];
            }
        }
        if (preg_match ($titles[$c], $saida[$i])) {
            for ($i++; $i < $cntlinhas; $i++) {
                if (preg_match ("/^\t([\\w ]+): (.*)\\s*\$/", $saida[$i], $matches)) {
                    if (in_array ($matches[1], $needed[$c])) {
                        if (isset ($found[$c][$matches[1]])) {
                            morre ("Saida do 'dmidecode' produziram paramatros duplicados!");
                        } else {
                            $found[$c][$matches[1]] = trim ($matches[2]);
                            $tudo = true;
                            foreach ($needed[$c] as $item) {
                                if (! isset ($found[$c][$item])) {
                                    $tudo = false;
                                    break;
                                }
                            }
                            if ($tudo) {
                                break;
                            }
                        }                
                    }
                }
            }
            break;
        }
    }
}
if (empty ($found[0]['BIOS Revision']) && empty ($found[0]['Firmware Revision'])) {
    $found[0]['BIOS Major'] = $found[0]['Firmware Major'] = $smbiosmajor;
    $found[0]['BIOS Minor'] = $found[0]['Firmware Minor'] = $smbiosminor;
} else {
    if (! empty ($found[0]['BIOS Revision'])) {
        $bm = explode ('.', $found[0]['BIOS Revision']);
        if (! empty ($bm[0])) $found[0]['BIOS Major'] = $bm[0];
        if (! empty ($bm[1])) $found[0]['BIOS Minor'] = $bm[1];
    }
    if (! empty ($found[0]['Firmware Revision'])) {
        $bm = explode ('.', $found[0]['Firmware Revision']);
        if (! empty ($bm[0])) $found[0]['Firmware Major'] = $bm[0];
        if (! empty ($bm[1])) $found[0]['Firmware Minor'] = $bm[1];
    }
}
foreach ($needed[1] as $item) {
    if (empty ($found[1][$item])) {
        $found[1][$item] = '';
    } else if ($found[1][$item] == 'NONE' || $found[1][$item] == 'Not Specified') {
        $found[1][$item] = '';
    }
}

$prefixo = "setextradata " . escapeshellarg ($vmuuid) . " VBoxInternal/Devices/pcbios/0/Config/";

if (! empty ($found[0]['Vendor']))         chamavbox ($prefixo . "DmiBiosVendor " .        escapeshellarg ($found[0]['Vendor']));
if (! empty ($found[0]['Version']))        chamavbox ($prefixo . "DmiBiosVersion " .       escapeshellarg ($found[0]['Version']));
if (! empty ($found[0]['Release Date']))   chamavbox ($prefixo . "DmiBiosReleaseDate " .   escapeshellarg ($found[0]['Release Date']));
if (! empty ($found[0]['BIOS Major']))     chamavbox ($prefixo . "DmiBiosReleaseMajor " .  escapeshellarg ($found[0]['BIOS Major']));
if (! empty ($found[0]['BIOS Minor']))     chamavbox ($prefixo . "DmiBiosReleaseMinor " .  escapeshellarg ($found[0]['BIOS Minor']));
if (! empty ($found[0]['Firmware Major'])) chamavbox ($prefixo . "DmiBiosFirmwareMajor " . escapeshellarg ($found[0]['Firmware Major']));
if (! empty ($found[0]['Firmware Minor'])) chamavbox ($prefixo . "DmiBiosFirmwareMinor " . escapeshellarg ($found[0]['Firmware Minor']));

if (! empty ($found[1]['Manufacturer']))  chamavbox ($prefixo . "DmiSystemVendor " .  escapeshellarg ($found[1]['Manufacturer']));
if (! empty ($found[1]['Product Name']))  chamavbox ($prefixo . "DmiSystemProduct " . escapeshellarg ($found[1]['Product Name']));
if (! empty ($found[1]['Version']))       chamavbox ($prefixo . "DmiSystemVersion " . escapeshellarg ($found[1]['Version']));
if (! empty ($found[1]['Serial Number'])) chamavbox ($prefixo . "DmiSystemSerial " .  escapeshellarg ($found[1]['Serial Number']));
if (! empty ($found[1]['UUID']))          chamavbox ($prefixo . "DmiSystemUuid " .    escapeshellarg ($found[1]['UUID']));
if (! empty ($found[1]['Family']))        chamavbox ($prefixo . "DmiSystemFamily " .  escapeshellarg ($found[1]['Family']));

exibe ("Detectando discos...\n");
$devroots = "/sys/class/block";
$dd = opendir ($devroots);
if ($dd === false) {
    morre ("Impossivel abrir a pasta '" . $devroots . "'!");
}
$discos = array ();
while (($d_entry = readdir ($dd)) !== false) {
    if (preg_match ("/^[hs]d[a-z]\$/", $d_entry)) {
        for ($c = 1; $c < 5; $c++) {
            if (file_exists ($devroots . "/" . $d_entry . $c)) {
                break;
            }
        }
        if ($c < 5) {
            $numbe = system ("sudo blockdev --getsize64 /dev/" . $d_entry, $retvar);
            if ($retvar == 0) {
                $numbe = (int) $numbe;
                if ($numbe >= 40000000000) {
                    $discos[] = $d_entry;
                }
            }
        }
    }
}
closedir ($dd);

if (empty ($discos)) {
    morre ("Nenhum disco utilizável foi encontrado na estação!");
}

foreach ($discos as $disco) {
    morre ("Criar os discos do VirtualBox aqui...");
}

exibe ("Teste feito.\n");
sai (0);


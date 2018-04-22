#!/usr/bin/php
<?php

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
    global $vmuuid, $workdir;
    if ($codsaida) {
        echo ("\n **** Pressione ENTER para continuar... ****\n");
        fgets (STDIN);
    }
    if (! empty ($vmuuid)) {
        sleep (2);
        passthru ("VBoxManage controlvm " . escapeshellarg ($vmuuid) . " poweroff");
        sleep (2);
	passthru ("VBoxManage unregistervm " . escapeshellarg ($vmuuid) . " --delete");
    }
    if (! empty ($workdir)) {
        passthru ("rm -Rfv " . escapeshellarg ($workdir));
    }
    exibe ("\n[ .FIM. ]\n");
    exit ($codsaida);
}

function morre ($msg) {
    exibe ($msg . "\n");
    exibe (" **** O programa não foi executado com sucesso! ****\n");
    sai (1);
}

function chamavbox ($cmdline) {
    $cmdline = "VBoxManage -q " . $cmdline;
    exibe ($cmdline . "\n");
    passthru ($cmdline, $retvar);
    if ($retvar !== 0) {
        morre ("Falha ao executar comando: '" . $cmdline . "'!");
    }
}

function pergunta ($prompt, $opcoes) {
    $cntlinhas = count ($opcoes);
    if ($cntlinhas > 1) {
        while (true) {
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

function is_blockdev ($path) {
    @ $st_array = stat ($path);
    if ($st_array !== false) {
        // $ man 2 stat
        return ($st_array['mode'] & 0060000);
    } else {
        return (false);
    }
}

//////////////////////////////////////////////////

exibe (" ++++ Script do AMG1127 para criar automaticamente uma máquina virtual para o boot do sistema operacional físico. ++++\n");
error_reporting (-1);

$disp = getenv ('DISPLAY');
if (empty ($disp)) {
    morre ('Este script precisa do modo gráfico para funcionar!');
}

$tmpdir = sys_get_temp_dir ();

$isvboxuser = 0;
$vboxgroups = array ('vboxusers', 'disk');
$newusername = 'vboxmaster';
$p_groups = posix_getgroups ();
if (empty ($p_groups)) {
    morre ("Falha ao listar os grupos efetivos do processo!");
}
foreach ($p_groups as $item) {
    $gr_info = posix_getgrgid ($item);
    if (! empty ($gr_info)) {
        if (in_array ($gr_info['name'], $vboxgroups)) {
            $isvboxuser++;
        }
    }
}
if (count($vboxgroups) != $isvboxuser) {
    exibe ("Usuário atual não pertence aos grupos ('" . implode ("', '", $vboxgroups) . "')... Trocando para o usuário '" . $newusername . "'...\n");
    $u_info = posix_getpwuid (posix_geteuid ());
    if ($u_info === false) {
        morre ("'posix_getpwuid()' falhou!");
    }
    if ($u_info['name'] == $newusername) {
        morre ("Usuario alvo (" . $newusername . ") também não pertence aos grupos ('" . implode ("', '", $vboxgroups) . "')!");
    }
    $pipe_r = popen ("xauth extract - " . escapeshellarg ($disp), "r");
    if ($pipe_r === false) {
        morre ("Falha ao executar 'xauth extract'!");
    }
    $pipe_w = popen ("sudo -u " . escapeshellarg ($newusername) . " -H -n -- xauth merge -", "w");
    if ($pipe_w === false) {
        morre ("Falha ao executar 'sudo xauth merge'!");
    }
    $dados = stream_get_contents ($pipe_r);
    if ($dados === false) {
        morre ("Falha ao ler dados de 'xauth extract'!");
    }
    if (fwrite ($pipe_w, $dados) === false) {
        morre ("Falha ao gravar dados para 'sudo xauth merge'!");
    }
    if (($retvar = pclose ($pipe_r))) {
        morre ("Comando 'xauth extract' terminou com saída #" . $retvar . "!");
    }
    if (($retvar = pclose ($pipe_w))) {
        morre ("Comando 'sudo xauth merge' terminou com saída #" . $retvar . "!");
    }
    exibe ("\n");
    $newscript = tempnam ($tmpdir, basename (__FILE__));
    if ($newscript === false) {
        morre ("Impossível criar arquivo temporário!");
    }
    if (! copy (__FILE__, $newscript)) {
        unlink ($newscript);
        morre ("Falha ao copiar código-fonte do script para o arquivo temporário!");
    }
    if (! chmod ($newscript, 0444)) {
        unlink ($newscript);
        morre ("Falha ao definir permissões de leitura do arquivo temporário!");
    }
    passthru ("sudo -u " . escapeshellarg ($newusername) . " DISPLAY=" . escapeshellarg ($disp) . " -- php " . escapeshellarg ($newscript), $retvar);
    unlink ($newscript);
    exit ($retvar);
}

$vmuuid = system ("uuidgen", $retvar);
if ($retvar) {
    morre ("Impossivel gerar um UUID para a nova máquina virtual!");
}
$vmuuid = trim ($vmuuid);
if (empty ($vmuuid)) {
    morre ("UUID da máquina virtual é valor vazio!?");
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
    if (preg_match ("/^\\d+:\\s+([\\w\\.@]+):\\s+<([^>]+)>\\s+/", $saida[$i], $matches)) {
        $ifflags = explode (',', $matches[2]);
        if (in_array ('UP', $ifflags) && (! in_array ('LOOPBACK', $ifflags)) && (! in_array ('NO-CARRIER', $ifflags))) {
            if (preg_match ("/^\\s+link\\/\\w+\\s+([a-fA-F0-9][a-fA-F0-9]:[a-fA-F0-9][a-fA-F0-9]:[a-fA-F0-9][a-fA-F0-9]:[a-fA-F0-9][a-fA-F0-9]:[a-fA-F0-9][a-fA-F0-9]:[a-fA-F0-9][a-fA-F0-9])\\s+/", $saida[$i+1], $macmatches)) {
                $ifaces[] = array ($matches[1], $macmatches[1]);
            }
        }
    } else {
        morre ("Linha #" . ($i + 1) . " do 'ip link show' não possui dados de interface de rede!");
    }
}

$iface_resp = pergunta ("Qual interface de rede deverá ser conectada à máquina virtual?", $ifaces);

$is_64 = (pergunta ("O sistema operacional original da estação de trabalho é de que arquitetura?", array ("x86 (32 bits)", "x64 (64 bits)")) == 1);

$dont_use_sata = false;
$nictype = "Am79C973";
$ostype = "Linux26";

if (pergunta ("É alguma versão do Windows?", array ("Não", "Sim")) == 1) {
    $ostype = "Windows7";
    exibe ("Certo... Nesse caso, ajustes devem ser feitos nesse sistema, ANTES que esse script prossiga:\n");
    exibe ("\t1. A aplicação 'MergeIDE' deve ser executada.\n");
    exibe ("\t2. Um novo perfil de hardware deve ser criado.\n");
    exibe ("\t3. A funcionalidade 'Windows XP Professional Fast Logon Optimization', se existente, podera ser desativada.\n\n");

    if (pergunta ("Esses ajustes já foram realizados?", array ("Não", "Sim")) == 0) {
        morre ("Finalizando script, pois sistema operacional não está pronto!");
    }

    if (pergunta ("O 'VirtualBox Guest Additions' já foi instalado na estação de trabalho virtual?", array ("Não", "Sim")) == 0) {
        exibe ("Certo. Nesse caso, os discos físicos encontrados serão conectados à máquina virtual através de controlador de disco IDE (PIIX4).\n");
        exibe ("Isso provocará perda de desempenho, pois o VirtualBox é otimizado para o uso de controlador de disco SATA (IntelAhci).\n");
        exibe ("Assim que a máquina virtual ligar, providencie a instalação do 'VirtualBox Guest Additions'.\n\n");
        $dont_use_sata = true;
    }
}

$enableUEFI = (pergunta ("Qual é a arquitetura de inicialização da máquina física?", array ("BIOS", "UEFI")) == 1);

$dont_use_mounted_disks = true;
if (pergunta ("Opção perigosa! Deseja expor para a máquina virtual discos que possuem partições montadas?", array ("Não", "Sim")) == 1) {
    exibe ("!!Isso é realmente perigoso!! Cuidado!! Erros podem resultar em severa corrupção de dados!\n");
    $dont_use_mounted_disks = false;
}

if ($is_64) {
    $ostype .= "_64";
    $nictype = "82540EM";
}

do {
    $workdir = $tmpdir . "/" . uniqid ("", true);
} while (! @ mkdir ($workdir, 0700));

// Sempre utilizar 20% da memoria total do sistema...
$meminfo = file ("/proc/meminfo");
if ($meminfo === false) {
    morre ("Falha ao ler arquivo '/proc/meminfo'!");
}
$vm_mem = false;
foreach ($meminfo as $linha) {
    if (preg_match ("/^\\s*MemTotal\\s*:\\s*(\\d+)\\s*kB\\s*\$/", trim ($linha), $matches)) {
        $vm_mem = round ($matches[1] / 5120);
        break;
    }
}
if ($vm_mem === false) {
    morre ("Falha ao detectar a quantidade total de memória do sistema!");
}

chamavbox ("createvm --name 'Sistema operacional original' --uuid " . escapeshellarg ($vmuuid) . " --basefolder " . escapeshellarg ($workdir) . " --register");

chamavbox ("modifyvm " . escapeshellarg ($vmuuid) . " " .
                               "--ostype " . $ostype . " " .
                               "--memory " . $vm_mem . " " .
                               "--vram 160 " .
                               "--accelerate3d off " .
                               "--accelerate2dvideo off " .
                               "--acpi on " .
                               "--ioapic on " .
                               "--pae on " .
                               "--cpus 2 " .
                               "--rtcuseutc off " .
                               "--cpuhotplug off " .
                               "--vtxvpid on " .
                               "--hwvirtex on " .
                               "--nestedpaging on " .
                               "--boot1 disk " .
                               "--boot2 none " .
                               "--boot3 none " .
                               "--boot4 none " .
                               "--nic1 null " .
                               "--nic2 bridged " .
                               "--bridgeadapter2 " . escapeshellarg ($ifaces[$iface_resp][0]) . " " .
                               "--macaddress1 " . str_replace (':', '', $ifaces[$iface_resp][1]) . " " .
                               "--macaddress2 auto " .
                               "--nictype1 " . $nictype . " " .
                               "--nictype2 " . $nictype . " " .
                               "--cableconnected1 off " .
                               "--cableconnected2 on " .
                               "--uart1 off " .
                               "--uart2 off " .
                               "--audio none " .
                               "--clipboard disabled " .
                               "--usb off " .
                               "--usbehci off " .
                               "--vrde off " .
                               "--mouse usbtablet ");

if ($enableUEFI) {
    chamavbox ("modifyvm " . escapeshellarg ($vmuuid) . " " .
                               "--firmware efi ");
} else {
    chamavbox ("modifyvm " . escapeshellarg ($vmuuid) . " " .
                               "--firmware bios " .
                               "--bioslogofadein on " .
                               "--bioslogofadeout on " .
                               "--biosbootmenu messageandmenu ");
}

chamavbox ("setextradata " . escapeshellarg ($vmuuid) . " " .
                         "GUI/ShowMiniToolBar no");

chamavbox ("storagectl " . escapeshellarg ($vmuuid) . " " .
                       "--name satactl " .
                       "--add sata " .
                       "--portcount 30 " .
                       "--bootable on " .
                       "--hostiocache off " .
                       "--controller IntelAhci");

chamavbox ("storagectl " . escapeshellarg ($vmuuid) . " " .
                       "--name idectl " .
                       "--add ide " .
                       "--bootable on " .
                       "--hostiocache off " .
                       "--controller ICH6");

exibe ("Obtendo informações do 'dmidecode' (com 'sudo')...\n");
$titles = array ('/^BIOS Information/', '/^System Information/');
$needed = array (
    0 => array (
        'Vendor',
        'Version',
        'Release Date',
        'BIOS Revision',
        'Firmware Revision'
    ),
    1 => array (
        'Manufacturer',
        'Product Name',
        'Version',
        'Serial Number',
        'UUID',
        'Family'
    )
);
$found = array ();
$smbiosmajor = "";
$smbiosminor = "";
for ($c = 0; $c < 2; $c++) {
    $saida = array ();
    exec ("sudo -u root -n -- dmidecode -t" . $c, $saida, $retvar);
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
    } else if ($found[1][$item] == 'NONE' || $found[1][$item] == 'Not Specified' || $found[1][$item] == 'Not Present' || $found[1][$item] == 'To Be Filled By O.E.M.') {
        $found[1][$item] = '';
    }
}

$prefixo = "setextradata " . escapeshellarg ($vmuuid) . " VBoxInternal/Devices/" . (($enableUEFI) ? "efi" : "pcbios") . "/0/Config/";
$mapa = array (
    array (0, 'Vendor',         'DmiBIOSVendor'       , 'string:'),
    array (0, 'Version',        'DmiBIOSVersion'      , 'string:'),
    array (0, 'Release Date',   'DmiBIOSReleaseDate'  , 'string:'),
    array (0, 'BIOS Major',     'DmiBIOSReleaseMajor' , ''),
    array (0, 'BIOS Minor',     'DmiBIOSReleaseMinor' , ''),
    array (0, 'Firmware Major', 'DmiBIOSFirmwareMajor', ''),
    array (0, 'Firmware Minor', 'DmiBIOSFirmwareMinor', ''),
    array (1, 'Manufacturer',   'DmiSystemVendor'     , 'string:'),
    array (1, 'Product Name',   'DmiSystemProduct'    , 'string:'),
    array (1, 'Version',        'DmiSystemVersion'    , 'string:'),
    array (1, 'Serial Number',  'DmiSystemSerial'     , 'string:'),
    array (1, 'UUID',           'DmiSystemUuid'       , 'string:'),
    array (1, 'Family',         'DmiSystemFamily'     , 'string:')
);
foreach ($mapa as $item) {
    if (! empty ($found[$item[0]][$item[1]])) {
        chamavbox ($prefixo . $item[2] . " " . escapeshellarg ($item[3] . $found[$item[0]][$item[1]]));
    }
}

exibe ("Detectando discos...\n");
$mounts = file ("/proc/mounts");
if ($mounts === false) {
    morre ("Impossível ler '/proc/mounts'!");
}
$mounteddevs = array ();
foreach ($mounts as $linha) {
    $elem = preg_split ("/\\s+/", trim ($linha));
    $mounteddevs[] = $elem[0];
}
$devroots = "/sys/class/block";
$dd = opendir ($devroots);
if ($dd === false) {
    morre ("Impossível abrir a pasta '" . $devroots . "'!");
}
$discos = array ();
while (($d_entry = readdir ($dd)) !== false) {
    if (preg_match ("/^[hs]d[a-z]\$/", $d_entry)) {
        for ($c = 1; $c < 5; $c++) {
            if (is_blockdev ($devroots . "/" . $d_entry . $c)) {
                break;
            }
        }
        if ($c < 5 && is_blockdev ("/dev/" . $d_entry)) {
            $numbe = system ("blockdev --getsize64 /dev/" . $d_entry, $retvar);
            if ($retvar == 0) {
                $numbe = (int) $numbe;
                if ($numbe >= 40000000000) {
                    $l_d_entry = strlen ($d_entry) + 5;
                    foreach ($mounteddevs as $item) {
                        if (substr ($item, 0, $l_d_entry) == ("/dev/" . $d_entry)) {
                            if (strpos (substr ($item, $l_d_entry), '/') === false) {
                                if ($dont_use_mounted_disks) {
                                    $d_entry = false;
                                    break;
                                }
                            }
                        }
                    }
                    if (! empty ($d_entry)) {
                        $discos[] = "/dev/" . $d_entry;
                    }
                }
            }
        }
    }
}
closedir ($dd);

if (empty ($discos)) {
    morre ("Nenhum disco utilizável foi encontrado na estação!");
}

$vboxdisco = "<@@VBOXGUESTDISK@@>";
$discos[] = $vboxdisco;
$ide_next = 0;
$sata_next = 0;
$vbguestadd = "/usr/lib/virtualbox/additions/VBoxGuestAdditions.iso";

if ($dont_use_sata) {
    $max_discs = 34;
} else {
    $max_discs = 4;
}

$cntdisco = 0;
foreach ($discos as $disco) {
    if ($cntdisco >= $max_discs) {
        morre ("Não há 'slots' suficientes para referenciar todos os discos físicos da estação de trabalho!");
    }

    if ($disco != $vboxdisco) {
        $vmdkfile = escapeshellarg ($workdir . "/vmdk_" . $cntdisco . ".vmdk");
        $dtype = "hdd";
	chamavbox ("internalcommands createrawvmdk -filename " . $vmdkfile . " -rawdisk " . escapeshellarg ($disco));
    } else {
        $vmdkfile = $vbguestadd;
        $dtype = "dvddrive";
    }

    if ($dont_use_sata || $disco == $vboxdisco || $sata_next >= 30) {
        $storage = "idectl";
        $port = ($ide_next >> 1);
        $device = ($ide_next & 1);
        $ide_next++;
    } else {
        $storage = "satactl";
        $port = $sata_next;
        $device = 0;
        $sata_next++;
    }

    chamavbox ("storageattach " . escapeshellarg ($vmuuid) . " " .
                              "--storagectl " . $storage . " " .
                              "--port " . $port . " " .
                              "--device " . $device . " " .
                              "--medium " . $vmdkfile . " " .
                              "--type " . $dtype);
    $cntdisco++;
}

exibe ("Máquina virtual configurada. Ligando-a...\n");
chamavbox ("startvm " . escapeshellarg ($vmuuid) . " --type gui");

exibe ("Esperando a máquina virtual desligar (em segundo plano)...\n");
$pid = pcntl_fork ();
if ($pid > 0) {
    exit (0);
}
sleep (10);
$nerros = 0;
$maxerros = 90;
while (true) {
    $saida = array ();
    exec ("VBoxManage list runningvms", $saida, $retvar);
    if ($retvar) {
    	$nerros++;
    	if ($nerros >= $maxerros) {
            morre ("Impossivel determinar lista de máquinas virtuais em execução!");
        } else {
            exibe ("Não foi possível determinar lista de máquinas virtuais em execução... Tentar-se-á fazer isso novamente mais tarde...\n");
        }
    } else {
        $nerros = 0;
        $rodando = false;
        foreach ($saida as $linha) {
            if (preg_match ("/\\s+{" . $vmuuid . "}\$/i", trim ($linha))) {
                $rodando = true;
                break;
            }
        }
        if (! $rodando) {
            break;
        }
    }
    sleep (1);
}

sai (0);

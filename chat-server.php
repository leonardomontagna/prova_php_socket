<?php
error_reporting(~E_WARNING);                               // Riduzione dei messaggi d'errore
$null = NULL;                                              // Variabile null
$host = '192.168.1.5';                                       // Indirizzo dell'host
$port = '5001';                                            // Numero di porta

$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);    // Creazione socket TCP/IP
socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);   // Impostazione porto riusabile
socket_bind($socket, 0, $port);                            // Bind del socket
socket_listen($socket);                                    // Socket in ascolto
banner();                                                  // Messaggio di Server pronto

$clients = array($socket);                                 // Array dei socket attivi
while (true) {                                             // Inizio del ciclo infinito di ascolto
  $changed = $clients;                                     // Gestione connessioni multiple
  socket_select($changed, $null, $null, 0, 10);            // Verifica delle variazioni nei socket
  if (in_array($socket, $changed)) {                       // Test per nuovi socket
    $socket_new = socket_accept($socket);                  // Accettazione nuovo socket
    $clients[] = $socket_new;                              // Aggiunta del socket all'array client
    $header = socket_read($socket_new, 1024);              // Lettura dei dati inviati dal socket
    handshake($header, $socket_new, $host, $port);         // Gestione fase di handshake
    socket_getpeername($socket_new, $ip);                  // Trova indirizzo ip del socket connesso
                                                           // Preparazione dati in formato JSON
    $response = mask(json_encode(array('type'=>'system', 'message'=>$ip.' connesso'))); 
    send_message($response);   
    echo "\nCONNESSO!!!!\n";                            // Notifica a tutti la nuova connessione
    $found_socket = array_search($socket, $changed);       // Alloca il nuovo socket
    unset($changed[$found_socket]);
  }
    
  foreach ($changed as $changed_socket) {                  // Loop su tutti i socket connessi
    while(socket_recv($changed_socket,$buf,1024,0) >= 1) { // Test sui dati in arrivo
      $received_text = unmask($buf);                       // Estrazione dei dati
      $tst_msg = json_decode($received_text);              // Decodifica JSON 
      if (!empty($tst_msg)) {
        $user_name = $tst_msg->name;                       // Nome del mittente
        $user_message = $tst_msg->message;                 // Testo del messaggio
        $user_color = $tst_msg->color;                     // Colore
      } 
      else {
        $user_message = "";
      }    

      if ($user_message != "") {                           // Preparazione dati per il client
        switch($user_message) {
          case "date": 
            $user_message = "<b>date</b><br>Oggi è il ".date('d.m.Y'); 
            break;
          case "time": 
            $user_message = "<b>time</b><br>Sono le ".date('H.i.s'); 
            break;    
          case "random": 
            $user_message = "<b>random</b><br>Il tuo numero fortunato è il ".rand(1,100); 
            break;
          case "port": 
            $user_message = "<b>port</b><br>Sei collegato alla porta n. ".$port; 
            break;
          case "help": 
            $st  = "<b>help</b>";
            $st .= "<br>Nel campo <b>Messaggio</b> si possono inserire:";
            $st .= "<br>- messaggi per la chat,";
            $st .= "<br>- comandi per il server.<br>"; 
            $user_message = $st; 
            break;
          case "quit": 
            $st  = "<b>quit</b>";
            $st .= "<br>Disconnesso su richiesta del Client.";
            $user_message = $st;
            $response_text = mask(json_encode(array('type'=>'usermsg', 'name'=>$user_name, 
                                                    'message'=>$user_message, 
                                                    'color'=>$user_color)));
            send_message($response_text);                  // Invio dei dati
            $found_socket = array_search($changed_socket, $clients);
            socket_getpeername($changed_socket, $ip);
            unset($clients[$found_socket]);
                                                           // Notifica a tutti la disconnessione
            $response = mask(json_encode(array('type'=>'system', 'message'=>$ip.' disconnesso')));
            send_message($response); 
            break;
        } 
        $response_text = mask(json_encode(array('type'=>'usermsg', 'name'=>$user_name, 
                                                'message'=>$user_message, 
                                                'color'=>$user_color)));
        send_message($response_text);                      // Invio dei dati
        break 2;                                           // Uscita dal loop
      }
    }
        
    $buf = @socket_read($changed_socket, 1024, PHP_NORMAL_READ);
    if ($buf === false) {                                  // Test su client disconnesso, e rimozione
      $found_socket = array_search($changed_socket, $clients);
      socket_getpeername($changed_socket, $ip);
      unset($clients[$found_socket]);
                                                           // Notifica a tutti la disconnessione
      $response = mask(json_encode(array('type'=>'system', 'message'=>$ip.' disconnesso')));
      send_message($response);
    }
  }
}
socket_close($socket);                                     // Chiusura del socket in ascolto

function send_message($msg) {
  global $clients;
  foreach($clients as $changed_socket) {
    @socket_write($changed_socket,$msg,strlen($msg));
  }
  return true;
}

function unmask($text) {                                   // Estrazione delle parti del messaggio
  $length = ord($text[1]) & 127;
  if ($length == 126) {
    $masks = substr($text, 4, 4);
    $data = substr($text, 8);
  }
  elseif ($length == 127) {
    $masks = substr($text, 10, 4);
    $data = substr($text, 14);
  }
  else {
    $masks = substr($text, 2, 4);
    $data = substr($text, 6);
  }
  $text = "";
  for ($i = 0; $i < strlen($data); ++$i) {
    $text .= $data[$i] ^ $masks[$i%4];
  }
  return $text;
}

function mask($text) {                                     // Assemblaggio delle parti del messaggio
  $b1 = 0x80 | (0x1 & 0x0f);
  $length = strlen($text); 
  if ($length <= 125)
    $header = pack('CC', $b1, $length);
  elseif ($length > 125 && $length < 65536)
    $header = pack('CCn', $b1, 126, $length);
  elseif ($length >= 65536)
    $header = pack('CCNN', $b1, 127, $length);
  return $header.$text;
}

function handshake($receved_header,$client_conn, $host, $port) {
  $headers = array();
  $lines = preg_split("/\r\n/", $receved_header);
  foreach($lines as $line) {
    $line = chop($line);
    if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
      $headers[$matches[1]] = $matches[2];
    }
  }
  $secKey = $headers['Sec-WebSocket-Key'];
  $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
                                                           // Header dell'handshake
  $upgrade  = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
    "Upgrade: websocket\r\n" .
    "Connection: Upgrade\r\n" .
    "WebSocket-Origin: $host\r\n" .
    "WebSocket-Location: ws://$host:$port/chat-server.php\r\n".
    "Sec-WebSocket-Accept:$secAccept\r\n\r\n";
  socket_write($client_conn,$upgrade,strlen($upgrade));
}

function banner() {
  $banner  = "               _    ____             _        _   \n";
  $banner .= " __      _____| |__/ ___|  ___   ___| | _____| |_\n ";
  $banner .= "\ \ /\ / / _ \ '_ \___ \ / _ \ / __| |/ / _ \ __|\n";
  $banner .= "  \ V  V /  __/ |_) |__) | (_) | (__|   <  __/ |_ \n";
  $banner .= "   \_/\_/ \___|_.__/____/ \___/ \___|_|\_\___|\__|\n";
  echo $banner;
  echo "\n    chat-server 1.0";
  echo "\n    in attesa dell'arrivo delle connessioni... \n";
}
?>
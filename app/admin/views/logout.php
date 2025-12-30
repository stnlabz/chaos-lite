<?php
@session_start();
unset($_SESSION['admin']);
echo '<div class="container my-4"><h1 class="h5">Logged out</h1><p><a 
href="/admin?action=login">Sign in again</a></p></div>';


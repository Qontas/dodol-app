<?php

namespace App\Support;

/** Pelanggaran aturan bisnis invoice (pesan aman ditampilkan ke super admin). */
class InvoiceException extends \DomainException {}

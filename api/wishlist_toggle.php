<?php
header('Content-Type: application/json');
http_response_code(410);
echo json_encode(['error' => 'Wishlist feature has been removed. Use cart instead.']);
exit;

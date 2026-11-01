-- Create database
CREATE DATABASE IF NOT EXISTS retrade_db;
USE retrade_db;

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('buyer', 'seller', 'admin') NOT NULL DEFAULT 'buyer',
    phone VARCHAR(15),
    address TEXT,
    balance DECIMAL(10,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Products table
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seller_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    image VARCHAR(255),
    category VARCHAR(50),
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Transactions table
CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    buyer_id INT NOT NULL,
    seller_id INT NOT NULL,
    product_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    admin_commission DECIMAL(10,2) NOT NULL,
    seller_amount DECIMAL(10,2) NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'refunded', 'released') DEFAULT 'pending',
    payment_method VARCHAR(50) DEFAULT 'wallet',
    delivery_status ENUM('pending', 'confirmed', 'disputed') DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- Categories table (optional for better organization)
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default admin user
INSERT INTO users (name, email, password, role, balance) VALUES 
('Admin', 'admin@retrade.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 10000.00);
-- Password is 'password' (hashed)

-- Insert sample categories
INSERT INTO categories (name, description) VALUES 
('Electronics', 'Electronic devices and gadgets'),
('Clothing', 'Fashion and apparel'),
('Books', 'Books and educational materials'),
('Home & Garden', 'Home improvement and gardening'),
('Sports', 'Sports equipment and accessories'),
('Toys', 'Toys and games'),
('Automotive', 'Car parts and accessories'),
('Health & Beauty', 'Health and beauty products');

-- Insert sample seller
INSERT INTO users (name, email, password, role, balance) VALUES 
('John Seller', 'seller@retrade.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'seller', 500.00);

-- Insert sample buyer
INSERT INTO users (name, email, password, role, balance) VALUES 
('Jane Buyer', 'buyer@retrade.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'buyer', 1000.00);

-- Insert sample products
INSERT INTO products (seller_id, title, description, price, image, category, status) VALUES 
(2, 'iPhone 13 Pro', 'Latest iPhone in excellent condition', 75000.00, 'iphone13.jpg', 'Electronics', 'approved'),
(2, 'Samsung Galaxy S21', 'Android smartphone with great camera', 55000.00, 'samsung_s21.jpg', 'Electronics', 'approved'),
(2, 'MacBook Air M1', 'Lightweight laptop perfect for work', 95000.00, 'macbook_air.jpg', 'Electronics', 'pending'),
(2, 'Nike Air Jordan', 'Premium basketball shoes', 12000.00, 'nike_jordan.jpg', 'Sports', 'approved'),
(2, 'Canon DSLR Camera', 'Professional photography camera', 45000.00, 'canon_dslr.jpg', 'Electronics', 'approved');

package J2EE.bai4.service;

import J2EE.bai4.model.Product;
import org.springframework.stereotype.Service;
import org.springframework.web.multipart.MultipartFile;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;
import java.nio.file.StandardCopyOption;
import java.util.ArrayList;
import java.util.List;
import java.util.UUID;
import java.util.Iterator;
@Service
public class ProductService {
    private List<Product> listProduct = new ArrayList<>();

    public List<Product> getAll() {
        return listProduct;
    }

    public void add(Product newProduct) {
        listProduct.add(newProduct);
    }

    // Xử lý lưu ảnh
    public void updateImage(Product newProduct, MultipartFile imageProduct) {
        if (!imageProduct.isEmpty()) {
            try {
                Path dirImages = Paths.get("target/classes/static/images"); // Lưu vào target để thấy ngay
                if (!Files.exists(dirImages)) {
                    Files.createDirectories(dirImages);
                }

                // Đổi tên file để tránh trùng lặp
                String newFileName = UUID.randomUUID() + "_" + imageProduct.getOriginalFilename();

                Path pathFileUpload = dirImages.resolve(newFileName);
                Files.copy(imageProduct.getInputStream(), pathFileUpload, StandardCopyOption.REPLACE_EXISTING);

                newProduct.setImage(newFileName);
            } catch (IOException e) {
                e.printStackTrace();
            }
        }
    }
    public Product get(int id) {
        return listProduct.stream()
                .filter(p -> p.getId() == id)
                .findFirst()
                .orElse(null);
    }

    // 2. Thêm hàm Xóa sản phẩm
    public void delete(int id) {
        // Dùng Iterator để xóa an toàn trong List
        Iterator<Product> iterator = listProduct.iterator();
        while (iterator.hasNext()) {
            Product p = iterator.next();
            if (p.getId() == id) {
                iterator.remove();
                break;
            }
        }
    }

    // 3. Thêm hàm Cập nhật (Sửa)
    public void update(Product editProduct) {
        Product currentProduct = get(editProduct.getId());
        if (currentProduct != null) {
            currentProduct.setName(editProduct.getName());
            currentProduct.setPrice(editProduct.getPrice());
            currentProduct.setCategory(editProduct.getCategory());
            
            // Nếu có ảnh mới thì mới cập nhật, không thì giữ ảnh cũ
            if (editProduct.getImage() != null && !editProduct.getImage().isEmpty()) {
                currentProduct.setImage(editProduct.getImage());
            }
        }
    }
}
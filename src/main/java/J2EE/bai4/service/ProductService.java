package J2EE.bai4.service;

import J2EE.bai4.model.Product;
import J2EE.bai4.repository.OrderItemRepository;
import J2EE.bai4.repository.ProductRepository;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.data.domain.Page;
import org.springframework.data.domain.PageRequest;
import org.springframework.data.domain.Pageable;
import org.springframework.data.domain.Sort;
import org.springframework.stereotype.Service;
import org.springframework.web.multipart.MultipartFile;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;
import java.nio.file.StandardCopyOption;
import java.util.List;
import java.util.UUID;

@Service
public class ProductService {
    @Autowired
    private ProductRepository productRepository;
    @Autowired
    private OrderItemRepository orderItemRepository;

    public List<Product> getAll() {
        return productRepository.findAll();
    }

    public Page<Product> getProductsWithPagination(String keyword, Integer categoryId, int pageNum, String sortField, String sortDir) {
        Sort sort = sortDir.equalsIgnoreCase(Sort.Direction.ASC.name()) ? Sort.by(sortField).ascending() : Sort.by(sortField).descending();
        Pageable pageable = PageRequest.of(pageNum - 1, 5, sort);
        return productRepository.searchAndFilter(keyword, categoryId, pageable);
    }

    public void add(Product newProduct) {
        productRepository.save(newProduct);
    }

    // Xử lý lưu ảnh
    public void updateImage(Product newProduct, MultipartFile imageProduct) {
        if (!imageProduct.isEmpty()) {
            try {
                Path dirTarget = Paths.get("target/classes/static/images");
                Path dirSrc = Paths.get("src/main/resources/static/images");
                
                if (!Files.exists(dirTarget)) Files.createDirectories(dirTarget);
                if (!Files.exists(dirSrc)) Files.createDirectories(dirSrc);

                // Đổi tên file để tránh trùng lặp
                String newFileName = UUID.randomUUID() + "_" + imageProduct.getOriginalFilename();

                // Lưu vào target để hiện thị ngay lập tức
                Path pathTarget = dirTarget.resolve(newFileName);
                Files.copy(imageProduct.getInputStream(), pathTarget, StandardCopyOption.REPLACE_EXISTING);

                // Lưu vào src để không bị mất khi Restart hay Clean project
                Path pathSrc = dirSrc.resolve(newFileName);
                Files.copy(pathTarget, pathSrc, StandardCopyOption.REPLACE_EXISTING);

                newProduct.setImage(newFileName);
            } catch (IOException e) {
                e.printStackTrace();
            }
        }
    }

    public Product get(Integer id) {
        return productRepository.findById(id).orElse(null);
    }

    public void delete(Integer id) {
        if (orderItemRepository.existsByProductId(id)) {
            throw new IllegalStateException("Không thể xóa sản phẩm vì đã có trong đơn hàng.");
        }
        productRepository.deleteById(id);
    }

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
            productRepository.save(currentProduct);
        }
    }
}
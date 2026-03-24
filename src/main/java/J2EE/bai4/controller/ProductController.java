package J2EE.bai4.controller;

import J2EE.bai4.model.Category; // Nhớ import Category
import J2EE.bai4.model.Product;
import J2EE.bai4.service.CategoryService;
import J2EE.bai4.service.ProductService;
import org.springframework.data.domain.Page;
import jakarta.validation.Valid;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.validation.BindingResult;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.multipart.MultipartFile;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.servlet.mvc.support.RedirectAttributes;

@Controller
@RequestMapping("/products")
public class ProductController {

    @Autowired
    private ProductService productService;
    @Autowired
    private CategoryService categoryService;

    @GetMapping("")
    public String index(Model model,
                        @RequestParam(value = "keyword", defaultValue = "") String keyword,
                        @RequestParam(value = "categoryId", required = false) Integer categoryId,
                        @RequestParam(value = "page", defaultValue = "1") int page,
                        @RequestParam(value = "sortField", defaultValue = "price") String sortField,
                        @RequestParam(value = "sortDir", defaultValue = "asc") String sortDir) {

        String searchKeyword = keyword.trim().isEmpty() ? null : keyword.trim();
        Page<Product> productPage = productService.getProductsWithPagination(searchKeyword, categoryId, page, sortField, sortDir);

        model.addAttribute("listproduct", productPage.getContent());
        model.addAttribute("currentPage", page);
        model.addAttribute("totalPages", productPage.getTotalPages());
        model.addAttribute("totalItems", productPage.getTotalElements());
        model.addAttribute("keyword", keyword);
        model.addAttribute("categoryId", categoryId);
        model.addAttribute("sortField", sortField);
        model.addAttribute("sortDir", sortDir);
        model.addAttribute("reverseSortDir", sortDir.equals("asc") ? "desc" : "asc");
        model.addAttribute("categories", categoryService.getAll());

        return "product/products";
    }

    @GetMapping("/add")
    public String create(Model model) {
        model.addAttribute("product", new Product());
        model.addAttribute("categories", categoryService.getAll());
        return "product/create";
    }

    @PostMapping("/add")
    public String create(@Valid Product newProduct,
                         BindingResult result,
                         @RequestParam("imageProduct") MultipartFile imageProduct,
                         @RequestParam("categoryId") int categoryId, // FIX: Nhận ID danh mục từ form
                         Model model) {
        
        // 1. Validate Ảnh (Nếu muốn bắt buộc phải có ảnh)
        if (imageProduct == null || imageProduct.isEmpty()) {
             // Gán lỗi vào trường "image"
             result.rejectValue("image", "error.image", "Vui lòng chọn hình ảnh!");
        }

        // 2. Kiểm tra lỗi validation (Bao gồm lỗi Name, Price và lỗi Ảnh vừa add ở trên)
        if (result.hasErrors()) {
            model.addAttribute("product", newProduct);
            model.addAttribute("categories", categoryService.getAll());
            return "product/create";
        }

        // 3. Xử lý lưu ảnh
        if (imageProduct != null && !imageProduct.isEmpty()) {
            productService.updateImage(newProduct, imageProduct);
        }

        // 4. FIX: Gán Category vào Product dựa trên ID
        Category category = categoryService.get(categoryId);
        newProduct.setCategory(category);

        // 5. Lưu sản phẩm
        productService.add(newProduct);
        return "redirect:/products";
    }
    @GetMapping("/edit/{id}")
    public String edit(@PathVariable("id") int id, Model model) {
        Product product = productService.get(id);
        if (product == null) {
            return "redirect:/products"; // Không thấy thì quay về danh sách
        }
        model.addAttribute("product", product);
        model.addAttribute("categories", categoryService.getAll());
        return "product/edit";
    }

    @PostMapping("/edit")
    public String edit(@Valid Product editProduct,
                       BindingResult result,
                       @RequestParam("imageProduct") MultipartFile imageProduct,
                       @RequestParam("categoryId") int categoryId,
                       Model model) {
        
        // Logic validation giống hệt phần Create
        if (result.hasErrors()) {
            model.addAttribute("product", editProduct);
            model.addAttribute("categories", categoryService.getAll());
            return "product/edit"; 
        }

        // Xử lý ảnh: Nếu có ảnh mới thì upload
        if (imageProduct != null && !imageProduct.isEmpty()) {
            productService.updateImage(editProduct, imageProduct);
        }

        // Gán lại category
        editProduct.setCategory(categoryService.get(categoryId));
        
        // Gọi service update
        productService.update(editProduct);
        return "redirect:/products";
    }

    // --- PHẦN MỚI: CHỨC NĂNG DELETE (XÓA) ---
    
    @GetMapping("/delete/{id}")
    public String delete(@PathVariable("id") int id, RedirectAttributes redirectAttributes) {
        try {
            productService.delete(id);
            redirectAttributes.addFlashAttribute("success", "Đã xóa sản phẩm thành công.");
        } catch (IllegalStateException e) {
            redirectAttributes.addFlashAttribute("error", e.getMessage());
        }
        return "redirect:/products";
    }
}